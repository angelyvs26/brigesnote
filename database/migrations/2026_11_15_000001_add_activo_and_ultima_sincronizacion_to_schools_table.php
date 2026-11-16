<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activo: false cuando el colegio dejó de aparecer en ESCALE tras una
 * sincronización completa y sin errores.
 * ultima_sincronizacion: inicio de la última ejecución en la que el colegio
 * apareció. Lleva microsegundos para distinguir ejecuciones del mismo segundo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('activo')->default(true)->after('dis_juntos');
            $table->timestamp('ultima_sincronizacion', precision: 6)->nullable()->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['activo', 'ultima_sincronizacion']);
        });
    }
};
