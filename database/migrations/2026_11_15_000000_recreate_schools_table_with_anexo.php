<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un mismo código modular (codMod) puede tener varias sedes (anexo), incluso en
 * distritos distintos. La clave pasa a ser un id autoincremental con un índice
 * único (codMod, anexo). La tabla se recrea: los datos se vuelven a obtener
 * con "php artisan sync:locations".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('schools');

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('codMod', 20);
            $table->string('anexo', 10)->default('0');
            $table->string('idDistrito')->nullable()->index();
            $table->string('nombreCenEdu')->nullable();
            $table->string('cenPob')->nullable();
            $table->string('dirCen')->nullable();
            $table->string('director')->nullable();
            $table->string('dis_crecer')->nullable();
            $table->string('dis_juntos')->nullable();
            $table->timestamps();

            $table->unique(['codMod', 'anexo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');

        Schema::create('schools', function (Blueprint $table) {
            $table->string('codMod')->primary();
            $table->string('idDistrito')->nullable()->index();
            $table->string('nombreCenEdu')->nullable();
            $table->string('cenPob')->nullable();
            $table->string('dirCen')->nullable();
            $table->string('director')->nullable();
            $table->string('dis_crecer')->nullable();
            $table->string('dis_juntos')->nullable();
            $table->timestamps();
        });
    }
};
