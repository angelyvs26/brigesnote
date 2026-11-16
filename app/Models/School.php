<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    protected $table = 'schools';

    protected $fillable = [
        'codMod',
        'anexo',
        'idDistrito',
        'nombreCenEdu',
        'cenPob',
        'dirCen',
        'director',
        'dis_crecer',
        'dis_juntos',
        'activo',
        'ultima_sincronizacion',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'ultima_sincronizacion' => 'datetime',
        ];
    }

    /**
     * Solo colegios que siguen apareciendo en ESCALE.
     */
    #[Scope]
    protected function activos(Builder $query): void
    {
        $query->where('activo', true);
    }
}
