<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_sincronizacion_esta_programada_mensualmente_sin_solapamiento(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'sync:locations'));

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame('0 0 1 * *', $event->expression); // monthly(): día 1 a las 00:00
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_las_vistas_se_pueden_cachear_para_php_artisan_optimize(): void
    {
        // Sin resources/views, "php artisan optimize" falla en el paso de vistas.
        try {
            $this->artisan('view:cache')->assertSuccessful();
        } finally {
            $this->artisan('view:clear');
        }
    }

    public function test_no_existen_las_tablas_de_usuarios_ni_de_colas(): void
    {
        foreach (['users', 'password_resets', 'failed_jobs'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "La tabla $table no debería existir");
        }

        foreach (['regions', 'provinces', 'districts', 'schools'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "La tabla $table debería existir");
        }
    }
}
