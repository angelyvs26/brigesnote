<?php

use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rutas cargadas con el prefijo "/api" y el grupo de middleware "api"
| (ver bootstrap/app.php).
|
*/

// Los parámetros son códigos de ubigeo: solo dígitos y longitud fija por nivel.
// Si no cumplen, la ruta no coincide y se responde 404.
Route::get('/regiones', [LocationController::class, 'getRegions']);
Route::get('/provincias/{idRegion}', [LocationController::class, 'getProvinces'])
    ->where('idRegion', '[0-9]{2}');
Route::get('/distritos/{idProvince}', [LocationController::class, 'getDistricts'])
    ->where('idProvince', '[0-9]{4}');
Route::get('/colegios/{ubigeo}', [LocationController::class, 'getSchools'])
    ->where('ubigeo', '[0-9]{6}');
