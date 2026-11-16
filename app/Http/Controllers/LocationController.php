<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Obtener todas las regiones desde la base de datos.
     */
    public function getRegions(): JsonResponse
    {
        $regions = Region::all();

        return response()->json([
            'status' => 'success',
            'data' => $regions,
        ]);
    }

    /**
     * Obtener las provincias de una región (los dos primeros caracteres de idProvincia).
     */
    public function getProvinces(string $idRegion): JsonResponse
    {
        $provinces = Province::whereRaw('LEFT(idProvincia, 2) = ?', [$idRegion])->get();

        if ($provinces->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron provincias para esta región.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $provinces,
        ]);
    }

    /**
     * Obtener los distritos de una provincia (los cuatro primeros caracteres de idDistrito).
     */
    public function getDistricts(string $idProvince): JsonResponse
    {
        $districts = District::whereRaw('LEFT(idDistrito, 4) = ?', [$idProvince])->get();

        if ($districts->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron distritos para esta provincia.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $districts,
        ]);
    }

    /**
     * Obtener los colegios de un distrito (ubigeo / idDistrito completo).
     */
    public function getSchools(string $ubigeo): JsonResponse
    {
        $schools = School::where('idDistrito', $ubigeo)->get();

        if ($schools->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se encontraron escuelas para este distrito.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $schools,
        ]);
    }
}
