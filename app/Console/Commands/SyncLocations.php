<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class SyncLocations extends Command
{
    private const BASE_URL = 'https://escale.minedu.gob.pe/padron/rest';

    /** Registros por página al consultar instituciones (parámetro "max" de ESCALE). */
    public const PAGE_SIZE = 500;

    /** Pausa entre peticiones para no saturar el servidor de ESCALE. */
    public const PAUSE_MS = 250;

    /** Tiempo máximo de espera por petición, en segundos. */
    private const TIMEOUT_SECONDS = 60;

    /** Reintentos ante errores de red o respuestas fallidas, y espera entre ellos (ms). */
    private const RETRY_TIMES = 3;

    private const RETRY_SLEEP_MS = 1000;

    /** Tope de seguridad de páginas por distrito (500 x 200 = 100 000 colegios). */
    private const MAX_PAGES = 200;

    protected $signature = 'sync:locations
        {--distrito=* : Sincroniza solo los colegios de estos ubigeos de distrito (6 dígitos); se puede repetir}';

    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API de ESCALE y las registra en la base de datos';

    /** @var array<int, string> */
    private array $errors = [];

    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        $districtIds = $this->option('distrito');

        if ($districtIds === []) {
            $districtIds = $this->syncUbigeo();
        } else {
            $this->info('Se omite la sincronización de regiones, provincias y distritos (opción --distrito).');
        }

        $saved = $this->syncAllSchools(array_values(array_unique($districtIds)));

        $this->newLine();
        $this->table(['Distritos procesados', 'Colegios guardados', 'Errores'], [
            [count(array_unique($districtIds)), $saved, count($this->errors)],
        ]);

        foreach ($this->errors as $error) {
            $this->error($error);
        }

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    /**
     * Fase 1: regiones, provincias y distritos.
     *
     * @return array<int, string> ubigeos de los distritos sincronizados
     */
    private function syncUbigeo(): array
    {
        $this->info('Sincronizando regiones, provincias y distritos...');

        $response = $this->get('regiones');

        if ($response === null) {
            $this->errors[] = 'Error al obtener regiones';

            return [];
        }

        $districtIds = [];

        foreach ($this->asList($response->json('region'), 'idRegion') as $region) {
            Region::updateOrCreate(
                ['idRegion' => $region['idRegion']],
                [
                    'nombreRegion' => $region['nombreRegion'] ?? null,
                    'pointX' => $region['pointX'] ?? null,
                    'pointY' => $region['pointY'] ?? null,
                    'zoom' => $region['zoom'] ?? null,
                ]
            );

            $this->line("Región {$region['idRegion']}", verbosity: 'v');

            foreach ($this->syncProvinces($region['idRegion']) as $provinceId) {
                array_push($districtIds, ...$this->syncDistricts($provinceId));
            }
        }

        $this->info('Ubigeo sincronizado: '.count($districtIds).' distritos.');

        return $districtIds;
    }

    /**
     * @return array<int, string> ids de las provincias sincronizadas
     */
    private function syncProvinces(string $regionId): array
    {
        $response = $this->get('provincias', ['idRegion' => $regionId]);

        if ($response === null) {
            $this->errors[] = "Error al obtener provincias para la región $regionId";

            return [];
        }

        $ids = [];

        foreach ($this->asList($response->json('provincia'), 'idProvincia') as $province) {
            Province::updateOrCreate(
                ['idProvincia' => $province['idProvincia']],
                [
                    'nombreProvincia' => $province['nombreProvincia'] ?? null,
                    'pointX' => $province['pointX'] ?? null,
                    'pointY' => $province['pointY'] ?? null,
                ]
            );

            $ids[] = $province['idProvincia'];
        }

        return $ids;
    }

    /**
     * @return array<int, string> ubigeos de los distritos sincronizados
     */
    private function syncDistricts(string $provinceId): array
    {
        $response = $this->get('distritos', ['idProvincia' => $provinceId]);

        if ($response === null) {
            $this->errors[] = "Error al obtener distritos para la provincia $provinceId";

            return [];
        }

        $ids = [];

        foreach ($this->asList($response->json('distrito'), 'idDistrito') as $district) {
            District::updateOrCreate(
                ['idDistrito' => $district['idDistrito']],
                ['nombreDistrito' => $district['nombreDistrito'] ?? null]
            );

            $ids[] = $district['idDistrito'];
        }

        return $ids;
    }

    /**
     * Fase 2: colegios de cada distrito, con barra de progreso.
     *
     * @param  array<int, string>  $districtIds
     */
    private function syncAllSchools(array $districtIds): int
    {
        $this->info('Sincronizando colegios...');

        $saved = 0;
        $bar = $this->output->createProgressBar(count($districtIds));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% | %message%');
        $bar->setMessage('');
        $bar->start();

        foreach ($districtIds as $districtId) {
            $bar->setMessage("distrito $districtId");
            $saved += $this->syncSchools($districtId);
            $bar->advance();
        }

        $bar->setMessage("$saved colegios");
        $bar->finish();

        return $saved;
    }

    /**
     * Recorre todas las páginas de instituciones de un distrito y las guarda por lotes.
     */
    private function syncSchools(string $districtId): int
    {
        $saved = 0;
        $previousPageKeys = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->get('instituciones', [
                'estados' => 1,
                'ubigeo' => $districtId,
                'start' => $page * self::PAGE_SIZE,
                'max' => self::PAGE_SIZE,
            ]);

            if ($response === null) {
                $this->errors[] = "Error al obtener escuelas para el distrito $districtId (página ".($page + 1).')';

                return $saved;
            }

            $items = $this->asList($response->json('items'), 'codMod');

            if ($items === []) {
                return $saved;
            }

            $rows = array_map(fn (array $school) => [
                'codMod' => (string) $school['codMod'],
                'anexo' => (string) ($school['anexo'] ?? '0'),
                'idDistrito' => $districtId,
                'nombreCenEdu' => $school['cenEdu'] ?? null,
                'cenPob' => $school['cenPob'] ?? null,
                'dirCen' => $school['dirCen'] ?? null,
                'director' => $school['director'] ?? null,
                'dis_crecer' => $school['dis_crecer'] ?? null,
                'dis_juntos' => $school['dis_juntos'] ?? null,
            ], $items);

            // Si ESCALE ignorara "start" devolvería siempre la misma página: se corta el bucle.
            $pageKeys = array_map(fn (array $row) => $row['codMod'].'-'.$row['anexo'], $rows);
            if ($pageKeys === $previousPageKeys) {
                $this->errors[] = "ESCALE devolvió páginas repetidas para el distrito $districtId; se detuvo la paginación";

                return $saved;
            }
            $previousPageKeys = $pageKeys;

            School::upsert(
                $rows,
                ['codMod', 'anexo'],
                ['idDistrito', 'nombreCenEdu', 'cenPob', 'dirCen', 'director', 'dis_crecer', 'dis_juntos']
            );

            $saved += count($rows);

            if (count($items) < self::PAGE_SIZE) {
                return $saved;
            }
        }

        $this->errors[] = "El distrito $districtId superó el tope de ".self::MAX_PAGES.' páginas';

        return $saved;
    }

    /**
     * GET a ESCALE con timeout, reintentos y una pausa previa.
     * Devuelve null si la petición falla definitivamente.
     */
    private function get(string $resource, array $query = []): ?Response
    {
        Sleep::for(self::PAUSE_MS)->milliseconds();

        try {
            $response = Http::acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
                ->get(self::BASE_URL.'/'.$resource, $query);
        } catch (ConnectionException $e) {
            $this->line("Error de conexión con ESCALE ($resource): {$e->getMessage()}", verbosity: 'v');

            return null;
        }

        return $response->successful() ? $response : null;
    }

    /**
     * La API de ESCALE devuelve un objeto suelto cuando hay un único registro
     * y una lista cuando hay varios. Normaliza ambos casos a una lista.
     *
     * @return array<int, array<string, mixed>>
     */
    private function asList(mixed $payload, string $keyField): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload[$keyField])) {
            return [$payload];
        }

        return array_values(array_filter(
            $payload,
            fn ($item) => is_array($item) && isset($item[$keyField])
        ));
    }
}
