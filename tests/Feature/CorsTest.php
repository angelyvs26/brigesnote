<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    /**
     * Evalúa config/cors.php con un valor concreto de CORS_ALLOWED_ORIGINS.
     *
     * @return array<string, mixed>
     */
    private function corsConfigWith(?string $value): array
    {
        $previous = getenv('CORS_ALLOWED_ORIGINS');

        $value === null ? putenv('CORS_ALLOWED_ORIGINS') : putenv("CORS_ALLOWED_ORIGINS=$value");
        unset($_ENV['CORS_ALLOWED_ORIGINS'], $_SERVER['CORS_ALLOWED_ORIGINS']);

        try {
            return require config_path('cors.php');
        } finally {
            $previous === false ? putenv('CORS_ALLOWED_ORIGINS') : putenv("CORS_ALLOWED_ORIGINS=$previous");
        }
    }

    public function test_por_defecto_solo_permite_localhost(): void
    {
        $this->assertSame(['http://localhost'], $this->corsConfigWith(null)['allowed_origins']);
    }

    public function test_lee_varios_origenes_separados_por_comas(): void
    {
        $this->assertSame(
            ['https://admision.ejemplo.pe', 'http://localhost:3000'],
            $this->corsConfigWith(' https://admision.ejemplo.pe , http://localhost:3000, ')['allowed_origins']
        );
    }

    public function test_con_un_solo_origen_la_cabecera_siempre_es_ese_origen(): void
    {
        // Con un único origen, HandleCors responde siempre ese origen: el navegador
        // de un sitio distinto lo rechaza porque no coincide con su Origin.
        config(['cors.allowed_origins' => ['https://admision.ejemplo.pe']]);

        $this->getJson('/api/regiones', ['Origin' => 'https://admision.ejemplo.pe'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admision.ejemplo.pe');

        $this->call('OPTIONS', '/api/regiones', server: [
            'HTTP_ORIGIN' => 'https://admision.ejemplo.pe',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://admision.ejemplo.pe');

        $this->getJson('/api/regiones', ['Origin' => 'https://otro-sitio.test'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admision.ejemplo.pe');
    }

    public function test_con_varios_origenes_solo_responde_a_los_permitidos(): void
    {
        config(['cors.allowed_origins' => ['https://admision.ejemplo.pe', 'http://localhost:3000']]);

        $this->getJson('/api/regiones', ['Origin' => 'http://localhost:3000'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');

        $this->getJson('/api/regiones', ['Origin' => 'https://otro-sitio.test'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_ya_no_permite_cualquier_origen(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }
}
