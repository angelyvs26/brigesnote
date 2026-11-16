<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de caracterización: fijan el contrato JSON que la API tenía en
 * Laravel 7 para que los clientes (p. ej. admisionweb) no noten la migración.
 */
class LocationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TS = '2024-12-22 01:00:00';

    private const TS_JSON = '2024-12-22T01:00:00.000000Z';

    protected function setUp(): void
    {
        parent::setUp();

        $ts = ['created_at' => self::TS, 'updated_at' => self::TS];

        DB::table('regions')->insert([
            ['idRegion' => '15', 'nombreRegion' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793, 'zoom' => 10] + $ts,
            ['idRegion' => '04', 'nombreRegion' => 'AREQUIPA', 'pointX' => -16.409047, 'pointY' => -71.537451, 'zoom' => 8] + $ts,
        ]);

        DB::table('provinces')->insert([
            ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA', 'pointX' => -12.046374, 'pointY' => -77.042793] + $ts,
            ['idProvincia' => '1502', 'nombreProvincia' => 'BARRANCA', 'pointX' => -10.75, 'pointY' => -77.7666667] + $ts,
            ['idProvincia' => '0401', 'nombreProvincia' => 'AREQUIPA', 'pointX' => -16.409047, 'pointY' => -71.537451] + $ts,
        ]);

        DB::table('districts')->insert([
            ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'] + $ts,
            ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON'] + $ts,
            ['idDistrito' => '040101', 'nombreDistrito' => 'AREQUIPA'] + $ts,
        ]);

        DB::table('schools')->insert([
            ['codMod' => '0273572', 'anexo' => '0', 'idDistrito' => '150101', 'nombreCenEdu' => 'JUAN JIMENEZ PIMENTEL', 'cenPob' => 'LIMA', 'dirCen' => 'JR. LAMPA 240', 'director' => 'PEDRO PEREZ', 'dis_crecer' => null, 'dis_juntos' => null] + $ts,
            ['codMod' => '0300012', 'anexo' => '0', 'idDistrito' => '150101', 'nombreCenEdu' => 'NUESTRA SEÑORA DE GUADALUPE', 'cenPob' => 'LIMA', 'dirCen' => 'AV. ALFONSO UGARTE 1050', 'director' => null, 'dis_crecer' => '1', 'dis_juntos' => '0'] + $ts,
            ['codMod' => '0400001', 'anexo' => '0', 'idDistrito' => '040101', 'nombreCenEdu' => 'I.E. AREQUIPA', 'cenPob' => 'CERCADO', 'dirCen' => null, 'director' => null, 'dis_crecer' => null, 'dis_juntos' => null] + $ts,
            // Mismo código modular con dos sedes (anexos) en distritos distintos.
            ['codMod' => '1066018', 'anexo' => '0', 'idDistrito' => '150101', 'nombreCenEdu' => 'ARZOBISPO LOAYZA', 'cenPob' => null, 'dirCen' => null, 'director' => null, 'dis_crecer' => null, 'dis_juntos' => null] + $ts,
            ['codMod' => '1066018', 'anexo' => 'D', 'idDistrito' => '150132', 'nombreCenEdu' => 'ARZOBISPO LOAYZA', 'cenPob' => null, 'dirCen' => null, 'director' => null, 'dis_crecer' => null, 'dis_juntos' => null] + $ts,
        ]);
    }

    public function test_regiones_devuelve_todas_las_regiones_con_tipos_originales(): void
    {
        $response = $this->getJson('/api/regiones');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(2, 'data');

        $lima = collect($response->json('data'))->firstWhere('idRegion', '15');

        // Contrato de tipos: decimales como string, zoom como entero, fechas ISO-8601.
        $this->assertSame([
            'idRegion' => '15',
            'nombreRegion' => 'LIMA',
            'pointX' => '-12.0463740',
            'pointY' => '-77.0427930',
            'zoom' => 10,
            'created_at' => self::TS_JSON,
            'updated_at' => self::TS_JSON,
        ], $lima);
    }

    public function test_provincias_filtra_por_los_dos_primeros_digitos(): void
    {
        $this->getJson('/api/provincias/15')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.idProvincia', '1501')
            ->assertJsonPath('data.0.pointX', '-12.0463740')
            ->assertJsonPath('data.1.idProvincia', '1502');
    }

    public function test_provincias_inexistentes_devuelve_404(): void
    {
        // Formato válido pero sin datos (los formatos inválidos están en RouteParameterValidationTest).
        $this->getJson('/api/provincias/88')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron provincias para esta región.',
            ]);
    }

    public function test_distritos_filtra_por_los_cuatro_primeros_digitos(): void
    {
        $this->getJson('/api/distritos/1501')
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => [
                    ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA', 'created_at' => self::TS_JSON, 'updated_at' => self::TS_JSON],
                    ['idDistrito' => '150102', 'nombreDistrito' => 'ANCON', 'created_at' => self::TS_JSON, 'updated_at' => self::TS_JSON],
                ],
            ]);
    }

    public function test_distritos_inexistentes_devuelve_404(): void
    {
        $this->getJson('/api/distritos/9999')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ]);
    }

    public function test_colegios_por_ubigeo(): void
    {
        $response = $this->getJson('/api/colegios/150101');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data');

        $school = collect($response->json('data'))->firstWhere('codMod', '0300012');

        $this->assertIsInt($school['id']);
        unset($school['id']);

        $this->assertSame([
            'codMod' => '0300012',
            'anexo' => '0',
            'idDistrito' => '150101',
            'nombreCenEdu' => 'NUESTRA SEÑORA DE GUADALUPE',
            'cenPob' => 'LIMA',
            'dirCen' => 'AV. ALFONSO UGARTE 1050',
            'director' => null,
            'dis_crecer' => '1',
            'dis_juntos' => '0',
            'activo' => true,
            'ultima_sincronizacion' => null,
            'created_at' => self::TS_JSON,
            'updated_at' => self::TS_JSON,
        ], $school);
    }

    public function test_un_codmod_con_varios_anexos_aparece_en_cada_distrito(): void
    {
        $this->getJson('/api/colegios/150101')
            ->assertOk()
            ->assertJsonFragment(['codMod' => '1066018', 'anexo' => '0', 'idDistrito' => '150101']);

        $this->getJson('/api/colegios/150132')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['codMod' => '1066018', 'anexo' => 'D', 'idDistrito' => '150132']);
    }

    public function test_colegios_inexistentes_devuelve_404(): void
    {
        $this->getJson('/api/colegios/000000')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ]);
    }

    public function test_la_api_mantiene_el_limite_de_60_peticiones_por_minuto(): void
    {
        $this->getJson('/api/regiones')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');
    }

    public function test_ruta_inexistente_devuelve_404_en_json(): void
    {
        $this->get('/api/no-existe')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
