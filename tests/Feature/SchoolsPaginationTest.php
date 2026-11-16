<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/colegios/150132';

    protected function setUp(): void
    {
        parent::setUp();

        // 120 colegios activos en 150132 (codMod 0000001..0000120), 5 inactivos y 3 de otro distrito.
        $now = now();
        $rows = [];
        foreach (range(1, 120) as $n) {
            $rows[] = ['codMod' => str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'anexo' => '0', 'idDistrito' => '150132', 'activo' => true];
        }
        foreach (range(121, 125) as $n) {
            $rows[] = ['codMod' => str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'anexo' => '0', 'idDistrito' => '150132', 'activo' => false];
        }
        foreach (range(126, 128) as $n) {
            $rows[] = ['codMod' => str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'anexo' => '0', 'idDistrito' => '150101', 'activo' => true];
        }

        // Insertadas en orden inverso para comprobar que la API ordena por codMod.
        School::insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], array_reverse($rows)));
    }

    public function test_por_defecto_devuelve_la_primera_pagina_de_50_con_metadatos(): void
    {
        $response = $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(50, 'data')
            ->assertExactJsonStructure([
                'status',
                'data' => ['*' => ['id', 'codMod', 'anexo', 'idDistrito', 'nombreCenEdu', 'cenPob', 'dirCen', 'director', 'dis_crecer', 'dis_juntos', 'activo', 'ultima_sincronizacion', 'created_at', 'updated_at']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to'],
                'links' => ['first', 'last', 'prev', 'next'],
            ]);

        $this->assertSame([
            'current_page' => 1,
            'per_page' => 50,
            'total' => 120, // solo activos y solo de este distrito
            'last_page' => 3,
            'from' => 1,
            'to' => 50,
        ], $response->json('meta'));

        $this->assertSame([
            'first' => '/api/colegios/150132?per_page=50&page=1',
            'last' => '/api/colegios/150132?per_page=50&page=3',
            'prev' => null,
            'next' => '/api/colegios/150132?per_page=50&page=2',
        ], $response->json('links'));

        $this->assertSame('0000001', $response->json('data.0.codMod'));
        $this->assertSame('0000050', $response->json('data.49.codMod'));
    }

    public function test_page_y_per_page_seleccionan_el_tramo_correcto(): void
    {
        $response = $this->getJson(self::URL.'?page=3&per_page=25')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 5)
            ->assertJsonPath('meta.from', 51)
            ->assertJsonPath('meta.to', 75)
            ->assertJsonPath('links.prev', '/api/colegios/150132?per_page=25&page=2')
            ->assertJsonPath('links.next', '/api/colegios/150132?per_page=25&page=4');

        $this->assertSame('0000051', $response->json('data.0.codMod'));
    }

    public function test_ultima_pagina_incompleta_y_sin_siguiente(): void
    {
        $this->getJson(self::URL.'?page=2&per_page=100')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.from', 101)
            ->assertJsonPath('meta.to', 120)
            ->assertJsonPath('links.next', null);
    }

    public function test_per_page_maximo_100_es_valido(): void
    {
        $this->getJson(self::URL.'?per_page=100')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_recorrer_todas_las_paginas_devuelve_cada_colegio_una_vez(): void
    {
        $codes = [];
        for ($page = 1; $page <= 3; $page++) {
            array_push($codes, ...array_column($this->getJson(self::URL."?page=$page")->json('data'), 'codMod'));
        }

        $this->assertCount(120, $codes);
        $this->assertCount(120, array_unique($codes));
    }

    public function test_pagina_posterior_a_la_ultima_devuelve_data_vacio(): void
    {
        $this->getJson(self::URL.'?page=99')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 120)
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('links.next', null);
    }

    public function test_distrito_sin_colegios_activos_sigue_respondiendo_404(): void
    {
        $this->getJson('/api/colegios/999999?page=1&per_page=10')
            ->assertNotFound()
            ->assertExactJson([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function parametrosInvalidos(): array
    {
        return [
            'per_page mayor a 100' => ['per_page=101', 'per_page'],
            'per_page cero' => ['per_page=0', 'per_page'],
            'per_page negativo' => ['per_page=-5', 'per_page'],
            'per_page texto' => ['per_page=muchos', 'per_page'],
            'per_page decimal' => ['per_page=10.5', 'per_page'],
            'page cero' => ['page=0', 'page'],
            'page negativa' => ['page=-1', 'page'],
            'page texto' => ['page=dos', 'page'],
        ];
    }

    #[DataProvider('parametrosInvalidos')]
    public function test_parametros_de_paginacion_invalidos_responden_422(string $query, string $campo): void
    {
        $this->getJson(self::URL."?$query")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$campo]);
    }
}
