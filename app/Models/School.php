<?php

namespace App\Models;

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
    ];
}
