<?php

namespace Tests\Feature;

use App\Http\Controllers\LocationController;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Colegios dados de baja: solo una sincronización completa y sin errores
 * marca como inactivos los colegios que no aparecieron en ESCALE.
 */
class SchoolDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://escale.minedu.gob.pe/padron/rest';

    private const TABLE = ['Distritos procesados', 'Colegios guardados', 'Colegios desactivados', 'Errores'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        // Estado previo: colegios guardados en una sincronización anterior.
        $this->existing('0000001', '150101');                 // sigue en ESCALE
        $this->existing('0000002', '150102');                 // sigue en ESCALE
        $this->existing('9999999', '150101');                 // ya no está en ESCALE
        $this->existing('8888888', '150102', activo: false);  // estaba de baja y reaparece
    }

    private function existing(string $codMod, string $district, bool $activo = true): void
    {
        School::create([
            'codMod' => $codMod,
            'anexo' => '0',
            'idDistrito' => $district,
            'nombreCenEdu' => "COLEGIO $codMod",
            'activo' => $activo,
            'ultima_sincronizacion' => now()->subMonth(),
        ]);
    }

    /**
     * Simula ESCALE con una región, una provincia y dos distritos.
     *
     * @param  callable(Request): mixed|null  $instituciones  respuesta para /instituciones
     */
    private function fakeEscale(?callable $instituciones = null): void
    {
        $instituciones ??= fn (Request $request) => Http::response((int) $request['start'] > 0 ? [] : ['items' => match ($request['ubigeo']) {
            '150101' => [['codMod' => '0000001', 'anexo' => '0', 'cenEdu' => 'UNO']],
            '150102' => [
                ['codMod' => '0000002', 'anexo' => '0', 'cenEdu' => 'DOS'],
                ['codMod' => '8888888', 'anexo' => '0', 'cenEdu' => 'REAPARECE'],
            ],
        }]);

        Http::fake([
            self::BASE.'/regiones*' => Http::response(['region' => [['idRegion' => '15', 'nombreRegion' => 'LIMA']]]),
            self::BASE.'/provincias*' => Http::response(['provincia' => ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']]),
            self::BASE.'/distritos*' => Http::response(['distrito' => [
                ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'],
                ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON'],
            ]]),
            self::BASE.'/instituciones*' => $instituciones,
        ]);
    }

    private function assertNingunColegioDesactivado(): void
    {
        $this->assertSame(
            ['0000001', '0000002', '9999999'],
            School::where('activo', true)->orderBy('codMod')->pluck('codMod')->all(),
            'Una sincronización fallida o parcial no debe desactivar colegios'
        );
    }

    public function test_sincronizacion_completa_sin_errores_desactiva_los_que_no_aparecieron(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations')
            ->expectsTable(self::TABLE, [[2, 3, 1, 0]])
            ->assertSuccessful();

        $this->assertFalse(School::firstWhere('codMod', '9999999')->activo);

        // Los que aparecieron quedan activos (incluido el que reaparece) y con la fecha de esta ejecución.
        $vistos = School::whereIn('codMod', ['0000001', '0000002', '8888888'])->get();
        $this->assertTrue($vistos->every->activo);
        $this->assertTrue($vistos->every(fn (School $s) => $s->ultima_sincronizacion->isToday()));

        // La fecha del que se desactivó no se toca: indica la última vez que se vio.
        $this->assertTrue(School::firstWhere('codMod', '9999999')->ultima_sincronizacion->lt(now()->subWeek()));
    }

    public function test_sincronizacion_fallida_no_desactiva_ningun_colegio(): void
    {
        // El distrito 150101 falla (ESCALE responde 500 en los 3 intentos).
        $this->fakeEscale(fn (Request $request) => match ($request['ubigeo']) {
            '150101' => Http::response('error', 500),
            default => Http::response((int) $request['start'] > 0 ? [] : ['items' => [
                ['codMod' => '0000002', 'anexo' => '0', 'cenEdu' => 'DOS'],
            ]]),
        });

        $this->artisan('sync:locations')
            ->expectsTable(self::TABLE, [[2, 1, '-', 1]])
            ->expectsOutput('No se desactivó ningún colegio porque la sincronización tuvo errores.')
            ->assertSuccessful();

        $this->assertNingunColegioDesactivado();
    }

    public function test_error_de_conexion_al_obtener_regiones_no_desactiva_ningun_colegio(): void
    {
        Http::fake([self::BASE.'/regiones*' => fn () => throw new ConnectionException('timeout')]);

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener regiones')
            ->expectsOutput('No se desactivó ningún colegio porque la sincronización tuvo errores.')
            ->assertSuccessful();

        $this->assertNingunColegioDesactivado();
    }

    public function test_respuesta_vacia_de_escale_no_desactiva_ningun_colegio(): void
    {
        // 200 OK pero sin regiones: no es un país vacío, es una respuesta anómala.
        Http::fake([self::BASE.'/regiones*' => Http::response(['region' => []])]);

        $this->artisan('sync:locations')
            ->expectsOutput('ESCALE no devolvió ningún distrito')
            ->assertSuccessful();

        $this->assertNingunColegioDesactivado();
    }

    public function test_sincronizacion_interrumpida_no_desactiva_ningun_colegio(): void
    {
        // Un fallo inesperado a mitad de camino detiene el comando.
        $this->fakeEscale(fn () => throw new RuntimeException('Interrumpido'));

        try {
            $this->artisan('sync:locations')->run();
            $this->fail('Se esperaba que el comando se interrumpiera');
        } catch (RuntimeException $e) {
            $this->assertSame('Interrumpido', $e->getMessage());
        }

        $this->assertNingunColegioDesactivado();
    }

    public function test_sincronizacion_parcial_con_distrito_no_desactiva_ningun_colegio(): void
    {
        $this->fakeEscale();

        $this->artisan('sync:locations', ['--distrito' => ['150101']])
            ->expectsTable(self::TABLE, [[1, 1, '-', 0]])
            ->expectsOutput('No se desactivó ningún colegio porque la sincronización fue parcial (--distrito).')
            ->assertSuccessful();

        $this->assertNingunColegioDesactivado();
    }

    public function test_la_api_solo_devuelve_colegios_activos(): void
    {
        School::where('codMod', '9999999')->update(['activo' => false]);

        $this->getJson('/api/colegios/150101')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codMod', '0000001')
            ->assertJsonPath('data.0.activo', true);

        // Un distrito cuyos colegios están todos de baja responde como "sin colegios".
        // (Se vacía la caché porque el cambio no viene de una sincronización.)
        School::where('idDistrito', '150101')->update(['activo' => false]);
        Cache::store(LocationController::CACHE_STORE)->flush();

        $this->getJson('/api/colegios/150101')
            ->assertNotFound()
            ->assertJsonPath('message', 'No se encontraron escuelas para este distrito.');
    }
}
