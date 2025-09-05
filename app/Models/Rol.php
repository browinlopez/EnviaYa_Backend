<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role as SpatieRole;

class Rol extends SpatieRole
{
    protected $table = 'rol'; // 👈 usamos tu tabla
    protected $primaryKey = 'rol_id';
    public $timestamps = false;

    protected $fillable = ['name'];
}
