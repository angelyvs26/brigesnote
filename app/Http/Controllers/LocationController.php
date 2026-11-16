<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    /** Store de caché de las respuestas de la API (ver config/cache.php). */
    public const CACHE_STORE = 'api';

    /** Vida máxima de una respuesta en caché; la sincronización la vacía antes. */
    private const CACHE_TTL_SECONDS = 86400;

    public const SCHOOLS_PER_PAGE = 50;

    public const SCHOOLS_MAX_PER_PAGE = 100;

    /**
     * Obtener todas las regiones desde la base de datos.
     */
    public function getRegions(): JsonResponse
    {
        return $this->cached('regiones', fn () => $this->success(Region::all()->toArray()));
    }

    /**
     * Obtener las provincias de una región (los dos primeros caracteres de idProvincia).
     *
     * Se usa LIKE 'xx%' (y no LEFT()) para que MySQL aproveche la clave primaria.
     * $idRegion llega validado como dígitos desde la ruta, sin comodines % ni _.
     */
    public function getProvinces(string $idRegion): JsonResponse
    {
        return $this->cached("provincias:$idRegion", function () use ($idRegion) {
            $provinces = Province::where('idProvincia', 'like', $idRegion.'%')->get();

            return $provinces->isEmpty()
                ? $this->notFound('No se encontraron provincias para esta región.')
                : $this->success($provinces->toArray());
        });
    }

    /**
     * Obtener los distritos de una provincia (los cuatro primeros caracteres de idDistrito).
     *
     * Igual que en provincias: LIKE 'xxxx%' sobre la clave primaria.
     */
    public function getDistricts(string $idProvince): JsonResponse
    {
        return $this->cached("distritos:$idProvince", function () use ($idProvince) {
            $districts = District::where('idDistrito', 'like', $idProvince.'%')->get();

            return $districts->isEmpty()
                ? $this->notFound('No se encontraron distritos para esta provincia.')
                : $this->success($districts->toArray());
        });
    }

    /**
     * Obtener los colegios activos de un distrito (ubigeo / idDistrito completo), paginados.
     *
     * ?page= (desde 1) y ?per_page= (1 a 100, por defecto 50). Valores inválidos: 422.
     * Si el distrito no tiene colegios activos: 404. Una página posterior a la
     * última devuelve 200 con "data" vacío.
     */
    public function getSchools(Request $request, string $ubigeo): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::SCHOOLS_MAX_PER_PAGE],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::SCHOOLS_PER_PAGE);

        return $this->cached("colegios:$ubigeo:page:$page:per_page:$perPage", function () use ($ubigeo, $page, $perPage) {
            $schools = School::activos()
                ->where('idDistrito', $ubigeo)
                ->orderBy('codMod')
                ->orderBy('anexo')
                ->paginate($perPage, page: $page)
                ->withPath("/api/colegios/$ubigeo")
                ->appends(['per_page' => $perPage]);

            if ($schools->total() === 0) {
                return $this->notFound('No se encontraron escuelas para este distrito.');
            }

            return [
                'status' => 200,
                'body' => [
                    'status' => 'success',
                    'data' => $schools->getCollection()->toArray(),
                    'meta' => [
                        'current_page' => $schools->currentPage(),
                        'per_page' => $schools->perPage(),
                        'total' => $schools->total(),
                        'last_page' => $schools->lastPage(),
                        'from' => $schools->firstItem(),
                        'to' => $schools->lastItem(),
                    ],
                    'links' => [
                        'first' => $schools->url(1),
                        'last' => $schools->url($schools->lastPage()),
                        'prev' => $schools->previousPageUrl(),
                        'next' => $schools->nextPageUrl(),
                    ],
                ],
            ];
        });
    }

    /**
     * Devuelve la respuesta guardada en caché o la construye y la guarda.
     * Se cachea el cuerpo ya convertido a array (no modelos) junto con el código HTTP.
     *
     * @param  Closure(): array{status: int, body: array<string, mixed>}  $build
     */
    private function cached(string $key, Closure $build): JsonResponse
    {
        $response = Cache::store(self::CACHE_STORE)->remember($key, self::CACHE_TTL_SECONDS, $build);

        return response()->json($response['body'], $response['status']);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function success(array $data): array
    {
        return ['status' => 200, 'body' => ['status' => 'success', 'data' => $data]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function notFound(string $message): array
    {
        return ['status' => 404, 'body' => ['status' => 'error', 'message' => $message]];
    }
}
