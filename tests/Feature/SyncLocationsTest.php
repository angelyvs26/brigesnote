<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://escale.minedu.gob.pe/padron/rest';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_sincroniza_en_cascada_listas_y_objetos_sueltos(): void
    {
        Http::fake([
            self::BASE.'/regiones*' => Http::response(['region' => [
                ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793, 'zoom' => 10],
            ]]),
            // Una sola provincia: ESCALE devuelve un objeto, no una lista.
            self::BASE.'/provincias*' => Http::response(['provincia' => [
                'idProvincia' => '1501', 'nombreProvincia' => 'LIMA', 'pointX' => -12.04, 'pointY' => -77.04,
            ]]),
            self::BASE.'/distritos*' => Http::response(['distrito' => [
                ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'],
                ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON'],
            ]]),
            self::BASE.'/instituciones*' => function (Request $request) {
                return match ($request['ubigeo']) {
                    '150101' => Http::response(['items' => [
                        ['codMod' => '0273572', 'cenEdu' => 'JUAN JIMENEZ PIMENTEL', 'cenPob' => 'LIMA', 'dirCen' => 'JR. LAMPA 240', 'director' => 'PEDRO PEREZ'],
                        // Registro incompleto: antes generaba "Undefined index".
                        ['codMod' => '0300012', 'cenEdu' => 'GUADALUPE'],
                    ]]),
                    // Sin colegios: ESCALE no envía "items".
                    default => Http::response([]),
                };
            },
        ]);

        $this->artisan('sync:locations')
            ->expectsOutput('No se encontraron registros de escuelas')
            ->expectsOutput('Sincronización completa.')
            ->assertSuccessful();

        $this->assertSame(1, Region::count());
        $this->assertSame(10, Region::find('15')->zoom);
        $this->assertSame(['1501'], Province::pluck('idProvincia')->all());
        $this->assertSame(['150101', '150102'], District::orderBy('idDistrito')->pluck('idDistrito')->all());
        $this->assertSame(2, School::count());

        $incompleto = School::find('0300012');
        $this->assertSame('150101', $incompleto->idDistrito);
        $this->assertNull($incompleto->dirCen);

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/instituciones?estados=1&ubigeo=150101'
            && $r->hasHeader('Accept', 'application/json'));
    }

    public function test_es_idempotente_y_actualiza_registros_existentes(): void
    {
        Region::create(['idRegion' => '15', 'nombreRegion' => 'NOMBRE VIEJO']);

        Http::fake([
            self::BASE.'/regiones*' => Http::response(['region' => [
                ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.0, 'pointY' => -77.0, 'zoom' => 10],
            ]]),
            self::BASE.'/provincias*' => Http::response(['provincia' => []]),
        ]);

        $this->artisan('sync:locations')->assertSuccessful();
        $this->artisan('sync:locations')->assertSuccessful();

        $this->assertSame(1, Region::count());
        $this->assertSame('LIMA', Region::find('15')->nombreRegion);
    }

    public function test_informa_error_si_escale_falla(): void
    {
        Http::fake([self::BASE.'/regiones*' => Http::response('error', 500)]);

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener regiones')
            ->assertSuccessful();

        $this->assertSame(0, Region::count());
    }
}
