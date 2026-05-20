<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Rol extends SpatieRole
{
    public $timestamps = false;

    protected $fillable = ['id', 'name', 'guard_name'];
}
