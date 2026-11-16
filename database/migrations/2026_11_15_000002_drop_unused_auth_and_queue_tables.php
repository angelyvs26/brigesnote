<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La API no tiene usuarios ni colas persistentes: se eliminan las tablas
 * users, password_resets y failed_jobs que venían del esqueleto de Laravel 7.
 * dropIfExists permite ejecutarla también en instalaciones nuevas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('failed_jobs');
    }

    public function down(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->text('connection')->nullable();
            $table->text('queue')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamp('failed_at')->useCurrent();
        });
    }
};
