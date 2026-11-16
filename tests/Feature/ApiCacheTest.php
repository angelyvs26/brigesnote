<?php

namespace Tests\Feature;

use App\Http\Controllers\LocationController;
use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ApiCacheTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://escale.minedu.gob.pe/padron/rest';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();

        Region::create(['idRegion' => '15', 'nombreRegion' => 'LIMA']);
        Province::create(['idProvincia' => '1501', 'nombreProvincia' => 'LIMA']);
        District::create(['idDistrito' => '150101', 'nombreDistrito' => 'LIMA']);
        School::create(['codMod' => '0000001', 'anexo' => '0', 'idDistrito' => '150101', 'nombreCenEdu' => 'NOMBRE VIEJO']);
    }

    private function cache(): Repository
    {
        return Cache::store(LocationController::CACHE_STORE);
    }

    /**
     * Cambia los datos directamente en la base (sin pasar por la sincronización).
     */
    private function cambiarDatosEnLaBase(): void
    {
        Region::where('idRegion', '15')->update(['nombreRegion' => 'LIMA NUEVA']);
        Province::where('idProvincia', '1501')->update(['nombreProvincia' => 'LIMA NUEVA']);
        District::where('idDistrito', '150101')->update(['nombreDistrito' => 'LIMA NUEVA']);
        School::where('codMod', '0000001')->update(['nombreCenEdu' => 'NOMBRE NUEVO']);
    }

    public function test_todos_los_endpoints_se_sirven_desde_cache(): void
    {
        $urls = ['/api/regiones', '/api/provincias/15', '/api/distritos/1501', '/api/colegios/150101'];

        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }

        $this->assertTrue($this->cache()->has('regiones'));
        $this->assertTrue($this->cache()->has('provincias:15'));
        $this->assertTrue($this->cache()->has('distritos:1501'));
        $this->assertTrue($this->cache()->has('colegios:150101:page:1:per_page:50'));

        // La segunda vez no se consulta la base de datos.
        DB::enableQueryLog();
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_la_clave_incluye_los_parametros_de_paginacion(): void
    {
        $this->getJson('/api/colegios/150101')->assertOk();
        $this->getJson('/api/colegios/150101?page=2&per_page=10')->assertOk();
        $this->getJson('/api/colegios/150101?per_page=10')->assertOk();

        $this->assertTrue($this->cache()->has('colegios:150101:page:1:per_page:50'));
        $this->assertTrue($this->cache()->has('colegios:150101:page:2:per_page:10'));
        $this->assertTrue($this->cache()->has('colegios:150101:page:1:per_page:10'));

        // Cada clave guarda su propia página: la 2 con per_page=10 está vacía.
        $this->getJson('/api/colegios/150101?page=2&per_page=10')->assertJsonCount(0, 'data');
        $this->getJson('/api/colegios/150101')->assertJsonCount(1, 'data');
    }

    public function test_los_404_tambien_se_cachean(): void
    {
        $this->getJson('/api/provincias/88')->assertNotFound();

        Province::create(['idProvincia' => '8801', 'nombreProvincia' => 'NUEVA']);

        $this->getJson('/api/provincias/88')
            ->assertNotFound()
            ->assertJsonPath('status', 'error');
    }

    public function test_sincronizacion_exitosa_invalida_la_cache(): void
    {
        $this->getJson('/api/regiones')->assertJsonPath('data.0.nombreRegion', 'LIMA');
        $this->getJson('/api/colegios/150101')->assertJsonPath('data.0.nombreCenEdu', 'NOMBRE VIEJO');

        $this->cambiarDatosEnLaBase();

        // Antes de sincronizar, la API sigue devolviendo lo cacheado.
        $this->getJson('/api/regiones')->assertJsonPath('data.0.nombreRegion', 'LIMA');
        $this->getJson('/api/colegios/150101')->assertJsonPath('data.0.nombreCenEdu', 'NOMBRE VIEJO');

        // Sincronización exitosa (parcial, sin errores) de un distrito sin cambios.
        Http::fake([self::BASE.'/instituciones*' => fn (Request $r) => Http::response(
            (int) $r['start'] > 0 ? [] : ['items' => [['codMod' => '0000001', 'anexo' => '0', 'cenEdu' => 'NOMBRE NUEVO']]]
        )]);

        $this->artisan('sync:locations', ['--distrito' => ['150101']])
            ->expectsOutput('Caché de la API limpiada.')
            ->assertSuccessful();

        $this->assertFalse($this->cache()->has('regiones'));
        $this->assertFalse($this->cache()->has('colegios:150101:page:1:per_page:50'));

        $this->getJson('/api/regiones')->assertJsonPath('data.0.nombreRegion', 'LIMA NUEVA');
        $this->getJson('/api/colegios/150101')->assertJsonPath('data.0.nombreCenEdu', 'NOMBRE NUEVO');
    }

    public function test_sincronizacion_con_errores_no_invalida_la_cache(): void
    {
        $this->getJson('/api/regiones')->assertJsonPath('data.0.nombreRegion', 'LIMA');

        $this->cambiarDatosEnLaBase();

        Http::fake([self::BASE.'/instituciones*' => Http::response('error', 500)]);

        $this->artisan('sync:locations', ['--distrito' => ['150101']])
            ->expectsOutput('No se limpió la caché de la API porque la sincronización tuvo errores.')
            ->assertSuccessful();

        $this->assertTrue($this->cache()->has('regiones'));
        $this->getJson('/api/regiones')->assertJsonPath('data.0.nombreRegion', 'LIMA');
    }

    public function test_limpiar_la_cache_de_la_api_no_borra_otras_caches(): void
    {
        Cache::put('otra-clave', 'valor');
        $this->getJson('/api/regiones')->assertOk();

        Http::fake([self::BASE.'/instituciones*' => Http::response([])]);
        $this->artisan('sync:locations', ['--distrito' => ['150101']])->assertSuccessful();

        $this->assertFalse($this->cache()->has('regiones'));
        $this->assertSame('valor', Cache::get('otra-clave'));
    }
}
