<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Rol extends SpatieRole
{
    protected $table = 'rol'; 
    protected $primaryKey = 'rol_id';

    public $incrementing = false;   // 👈 evita que intente autoincrementar
    protected $keyType = 'int';      // 👈 tu PK es entero
    public $timestamps = false;

    protected $fillable = ['rol_id', 'name', 'guard_name'];
}
