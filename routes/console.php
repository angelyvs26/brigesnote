<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronización mensual con ESCALE. withoutOverlapping() evita que se ejecute
// dos veces a la vez si una sincronización tarda más de lo normal.
Schedule::command('sync:locations')->monthly()->withoutOverlapping();
