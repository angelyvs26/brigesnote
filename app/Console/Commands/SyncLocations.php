<?php

namespace App\Console\Commands;

use App\Models\District;
use App\Models\Province;
use App\Models\Region;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SyncLocations extends Command
{
    private const BASE_URL = 'https://escale.minedu.gob.pe/padron/rest';

    protected $signature = 'sync:locations';

    protected $description = 'Sincroniza regiones, provincias, distritos y escuelas desde la API y las registra en la base de datos';

    public function handle(): int
    {
        $this->info('Iniciando sincronización de ubicaciones...');

        // Sincronizar regiones (en cascada: provincias, distritos y escuelas)
        $this->syncRegions();

        $this->info('Sincronización completa.');

        return self::SUCCESS;
    }

    private function syncRegions(): void
    {
        $this->info('Sincronizando regiones...');
        $response = $this->get('regiones');

        if ($response->failed()) {
            $this->error('Error al obtener regiones');

            return;
        }

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

            $this->syncProvinces($region['idRegion']);
        }

        $this->info('Regiones sincronizadas correctamente.');
    }

    private function syncProvinces(string $regionId): void
    {
        $this->info("Sincronizando provincias para la región $regionId...");
        $response = $this->get('provincias', ['idRegion' => $regionId]);

        if ($response->failed()) {
            $this->error("Error al obtener provincias para la región $regionId");

            return;
        }

        foreach ($this->asList($response->json('provincia'), 'idProvincia') as $province) {
            Province::updateOrCreate(
                ['idProvincia' => $province['idProvincia']],
                [
                    'nombreProvincia' => $province['nombreProvincia'] ?? null,
                    'pointX' => $province['pointX'] ?? null,
                    'pointY' => $province['pointY'] ?? null,
                ]
            );

            $this->syncDistricts($province['idProvincia']);
        }

        $this->info("Provincias para la región $regionId sincronizadas correctamente.");
    }

    private function syncDistricts(string $provinceId): void
    {
        $this->info("Sincronizando distritos para la provincia $provinceId...");
        $response = $this->get('distritos', ['idProvincia' => $provinceId]);

        if ($response->failed()) {
            $this->error("Error al obtener distritos para la provincia $provinceId");

            return;
        }

        foreach ($this->asList($response->json('distrito'), 'idDistrito') as $district) {
            District::updateOrCreate(
                ['idDistrito' => $district['idDistrito']],
                [
                    'nombreDistrito' => $district['nombreDistrito'] ?? null,
                ]
            );

            $this->syncSchools($district['idDistrito']);
        }

        $this->info("Distritos para la provincia $provinceId sincronizados correctamente.");
    }

    private function syncSchools(string $districtId): void
    {
        $this->info("Sincronizando escuelas para el distrito $districtId...");
        $response = $this->get('instituciones', [
            'estados' => 1,
            'ubigeo' => $districtId,
        ]);

        if ($response->failed()) {
            $this->error("Error al obtener escuelas para el distrito $districtId");

            return;
        }

        if ($response->json('items') === null) {
            $this->error('No se encontraron registros de escuelas');

            return;
        }

        foreach ($this->asList($response->json('items'), 'codMod') as $school) {
            School::updateOrCreate(
                ['codMod' => $school['codMod']],
                [
                    'idDistrito' => $districtId,
                    'nombreCenEdu' => $school['cenEdu'] ?? null,
                    'cenPob' => $school['cenPob'] ?? null,
                    'dirCen' => $school['dirCen'] ?? null,
                    'director' => $school['director'] ?? null,
                    'dis_crecer' => $school['dis_crecer'] ?? null,
                    'dis_juntos' => $school['dis_juntos'] ?? null,
                ]
            );
        }

        $this->info("Escuelas para el distrito $districtId sincronizadas correctamente.");
    }

    private function get(string $resource, array $query = []): Response
    {
        return Http::acceptJson()->get(self::BASE_URL.'/'.$resource, $query);
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
