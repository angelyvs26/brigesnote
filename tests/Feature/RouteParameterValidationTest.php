<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Los parámetros de ruta son códigos de ubigeo: solo dígitos y longitud fija
 * (región 2, provincia 4, distrito 6). Cualquier otro valor responde 404 sin
 * llegar a la base de datos.
 */
class RouteParameterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $ts = ['created_at' => now(), 'updated_at' => now()];

        DB::table('provinces')->insert([
            ['idProvincia' => '1501', 'nombreProvincia' => 'LIMA'] + $ts,
            ['idProvincia' => '0401', 'nombreProvincia' => 'AREQUIPA'] + $ts,
        ]);

        DB::table('districts')->insert([
            ['idDistrito' => '150101', 'nombreDistrito' => 'LIMA'] + $ts,
            ['idDistrito' => '040101', 'nombreDistrito' => 'AREQUIPA'] + $ts,
        ]);

        DB::table('schools')->insert([
            ['codMod' => '0273572', 'anexo' => '0', 'idDistrito' => '150101'] + $ts,
            ['codMod' => '0400001', 'anexo' => '0', 'idDistrito' => '040101'] + $ts,
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validos(): array
    {
        return [
            'provincias de Lima' => ['/api/provincias/15', '1501'],
            'provincias con cero inicial' => ['/api/provincias/04', '0401'],
            'distritos de Lima' => ['/api/distritos/1501', '150101'],
            'distritos con cero inicial' => ['/api/distritos/0401', '040101'],
            'colegios de Cercado' => ['/api/colegios/150101', '0273572'],
            'colegios con cero inicial' => ['/api/colegios/040101', '0400001'],
        ];
    }

    #[DataProvider('validos')]
    public function test_parametros_validos_responden_200(string $url, string $esperado): void
    {
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertSee($esperado);
    }

    /**
     * Las URL van codificadas: %25 = "%", %20 = espacio, %2B = "+".
     *
     * @return array<string, array{string}>
     */
    public static function invalidos(): array
    {
        return [
            // Provincias: región de 2 dígitos.
            'region de 1 digito' => ['/api/provincias/1'],
            'region de 3 digitos' => ['/api/provincias/150'],
            'region con letras' => ['/api/provincias/ab'],
            'region alfanumerica' => ['/api/provincias/1a'],
            'region negativa' => ['/api/provincias/-1'],
            'region con comodin %' => ['/api/provincias/1%25'],
            'region con comodin _' => ['/api/provincias/1_'],
            'region con espacio' => ['/api/provincias/15%20'],
            // Distritos: provincia de 4 dígitos.
            'provincia de 3 digitos' => ['/api/distritos/150'],
            'provincia de 5 digitos' => ['/api/distritos/15010'],
            'provincia con letras' => ['/api/distritos/abcd'],
            'provincia con comodin %' => ['/api/distritos/15%25%25'],
            'provincia con comodin _' => ['/api/distritos/15__'],
            'provincia decimal' => ['/api/distritos/15.1'],
            // Colegios: distrito de 6 dígitos.
            'ubigeo de 5 digitos' => ['/api/colegios/15010'],
            'ubigeo de 7 digitos' => ['/api/colegios/1501011'],
            'ubigeo alfanumerico' => ['/api/colegios/15010a'],
            'ubigeo con signo' => ['/api/colegios/%2B150101'],
            'ubigeo con espacio' => ['/api/colegios/150%20101'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_parametros_invalidos_responden_404_sin_consultar_la_base(string $url): void
    {
        DB::enableQueryLog();

        $this->getJson($url)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonMissingPath('data');

        $this->assertSame([], DB::getQueryLog(), "La URL $url no debería llegar a la base de datos");
    }

    public function test_un_comodin_no_amplia_la_busqueda(): void
    {
        // Sin validación, "1%" generaría LIKE '1%%' y devolvería provincias de otras regiones.
        $this->getJson('/api/provincias/1%25')->assertNotFound();
        $this->getJson('/api/distritos/%25%25%25%25')->assertNotFound();
    }
}
