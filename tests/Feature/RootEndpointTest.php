<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootEndpointTest extends TestCase
{
    public function test_la_raiz_devuelve_nombre_version_y_estado_de_la_api(): void
    {
        config(['app.name' => 'Bridges Note', 'app.version' => '2.0.0']);

        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'name' => 'Bridges Note',
                'version' => '2.0.0',
                'status' => 'ok',
            ]);
    }
}
