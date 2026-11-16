<?php

namespace Tests\Feature;

use App\Console\Commands\SyncLocations;
use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncLocationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://escale.minedu.gob.pe/padron/rest';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    /**
     * Genera $count instituciones con códigos consecutivos a partir de $from.
     *
     * @return array<int, array<string, string>>
     */
    private function schools(int $from, int $count, string $anexo = '0'): array
    {
        return array_map(fn (int $n) => [
            'codMod' => str_pad((string) $n, 7, '0', STR_PAD_LEFT),
            'anexo' => $anexo,
            'cenEdu' => "COLEGIO $n",
            'cenPob' => 'LIMA',
            'dirCen' => "AV. $n",
        ], range($from, $from + $count - 1));
    }

    /**
     * Simula ESCALE paginando de verdad: corta $all según start/max.
     *
     * @param  array<string, array<int, array<string, string>>>  $byDistrict
     */
    private function fakeInstituciones(array $byDistrict): \Closure
    {
        return function (Request $request) use ($byDistrict) {
            $all = $byDistrict[$request['ubigeo']] ?? [];
            $page = array_slice($all, (int) $request['start'], (int) $request['max']);

            return Http::response($page === [] ? [] : ['items' => $page]);
        };
    }

    public function test_recorre_todas_las_paginas_de_un_distrito(): void
    {
        // 1 203 colegios = 2 páginas llenas de 500 + 1 página de 203.
        Http::fake([
            self::BASE.'/instituciones*' => $this->fakeInstituciones([
                '150132' => $this->schools(1, 1203),
            ]),
        ]);

        $this->artisan('sync:locations', ['--distrito' => ['150132']])
            ->expectsOutputToContain('Se omite la sincronización de regiones')
            ->expectsOutput('Sincronización completa.')
            ->assertSuccessful();

        $this->assertSame(1203, School::where('idDistrito', '150132')->count());

        // Se pidieron exactamente las páginas start=0, 500 y 1000, con max=500.
        Http::assertSentCount(3);
        foreach ([0, 500, 1000] as $start) {
            Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/instituciones')
                && $r['start'] == $start
                && $r['max'] == SyncLocations::PAGE_SIZE
                && $r['estados'] == 1
                && $r->hasHeader('Accept', 'application/json'));
        }

        // Pausa corta antes de cada petición.
        Sleep::assertSleptTimes(3);
    }

    public function test_pide_una_pagina_extra_cuando_la_ultima_esta_llena(): void
    {
        Http::fake([
            self::BASE.'/instituciones*' => $this->fakeInstituciones([
                '150101' => $this->schools(1, 500),
            ]),
        ]);

        $this->artisan('sync:locations', ['--distrito' => ['150101']])->assertSuccessful();

        $this->assertSame(500, School::count());
        Http::assertSentCount(2);
    }

    public function test_un_mismo_codmod_con_distinto_anexo_se_guarda_en_cada_distrito(): void
    {
        $sede = fn (string $anexo) => ['codMod' => '1066018', 'anexo' => $anexo, 'cenEdu' => 'ARZOBISPO LOAYZA'];

        // En 150132 hay un único registro: ESCALE devuelve un objeto suelto, no una lista.
        Http::fake([
            self::BASE.'/instituciones*' => function (Request $request) use ($sede) {
                if ((int) $request['start'] > 0) {
                    return Http::response([]);
                }

                return Http::response(['items' => $request['ubigeo'] === '150132'
                    ? $sede('D')
                    : [$sede('0'), $sede('1')]]);
            },
        ]);

        $this->artisan('sync:locations', ['--distrito' => ['150101', '150132']])->assertSuccessful();

        $this->assertSame(3, School::where('codMod', '1066018')->count());
        $this->assertSame(['0', '1'], School::where('idDistrito', '150101')->orderBy('anexo')->pluck('anexo')->all());
        $this->assertSame(['D'], School::where('idDistrito', '150132')->pluck('anexo')->all());
    }

    public function test_es_idempotente_y_actualiza_por_codmod_y_anexo(): void
    {
        $nombre = 'NOMBRE VIEJO';

        Http::fake([
            self::BASE.'/instituciones*' => function (Request $request) use (&$nombre) {
                return Http::response((int) $request['start'] > 0 ? [] : ['items' => [
                    ['codMod' => '0273572', 'anexo' => '0', 'cenEdu' => $nombre],
                    ['codMod' => '0273572', 'anexo' => '1', 'cenEdu' => $nombre],
                ]]);
            },
        ]);

        $this->artisan('sync:locations', ['--distrito' => ['150101']])->assertSuccessful();
        $idsAntes = School::orderBy('anexo')->pluck('id')->all();

        $nombre = 'JUAN JIMENEZ PIMENTEL';
        $this->artisan('sync:locations', ['--distrito' => ['150101']])->assertSuccessful();

        $this->assertSame(2, School::count());
        $this->assertSame($idsAntes, School::orderBy('anexo')->pluck('id')->all());
        $this->assertSame(['JUAN JIMENEZ PIMENTEL'], School::distinct()->pluck('nombreCenEdu')->all());
    }

    public function test_sin_opcion_sincroniza_ubigeo_completo_y_luego_colegios(): void
    {
        Http::fake([
            self::BASE.'/regiones*' => Http::response(['region' => [
                ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793, 'zoom' => 10],
            ]]),
            // Una sola provincia: objeto suelto.
            self::BASE.'/provincias*' => Http::response(['provincia' => [
                'idProvincia' => '1501', 'nombreProvincia' => 'LIMA', 'pointX' => -12.04, 'pointY' => -77.04,
            ]]),
            self::BASE.'/distritos*' => Http::response(['distrito' => [
                ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'],
                ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON'],
            ]]),
            self::BASE.'/instituciones*' => $this->fakeInstituciones([
                '150101' => $this->schools(1, 3),
                // 150102 sin colegios: ESCALE no envía "items".
            ]),
        ]);

        $this->artisan('sync:locations')
            ->expectsOutputToContain('Ubigeo sincronizado: 2 distritos.')
            ->expectsTable(['Distritos procesados', 'Colegios guardados', 'Colegios desactivados', 'Errores'], [[2, 3, 0, 0]])
            ->assertSuccessful();

        $this->assertSame(10, Region::find('15')->zoom);
        $this->assertSame(['1501'], Province::pluck('idProvincia')->all());
        $this->assertSame(['150101', '150102'], District::orderBy('idDistrito')->pluck('idDistrito')->all());
        $this->assertSame(3, School::where('idDistrito', '150101')->count());
    }

    public function test_reintenta_y_continua_con_el_siguiente_distrito_si_uno_falla(): void
    {
        Http::fake([
            self::BASE.'/instituciones*' => function (Request $request) {
                return match ($request['ubigeo']) {
                    '150101' => Http::response('error', 500),
                    default => Http::response((int) $request['start'] > 0 ? [] : ['items' => $this->schools(1, 2)]),
                };
            },
        ]);

        $this->artisan('sync:locations', ['--distrito' => ['150101', '040101']])
            ->expectsTable(['Distritos procesados', 'Colegios guardados', 'Colegios desactivados', 'Errores'], [[2, 2, '-', 1]])
            ->expectsOutput('Error al obtener escuelas para el distrito 150101 (página 1)')
            ->assertSuccessful();

        // 3 intentos al distrito que falla + 1 página al que funciona.
        Http::assertSentCount(4);
        $this->assertSame(2, School::where('idDistrito', '040101')->count());
    }

    public function test_un_error_de_conexion_no_detiene_la_sincronizacion(): void
    {
        Http::fake([
            self::BASE.'/regiones*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->artisan('sync:locations')
            ->expectsOutput('Error al obtener regiones')
            ->expectsOutput('Sincronización completa.')
            ->assertSuccessful();

        $this->assertSame(0, Region::count());
    }
}
