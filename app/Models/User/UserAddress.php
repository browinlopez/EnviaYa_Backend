<?php

namespace App\Models\User;

use App\Models\Maps\Alias;
use App\Models\Maps\City;
use App\Models\Maps\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    protected $table = 'user_address';
    protected $primaryKey = 'address_id';

    protected $fillable = [
        'user_id',
        'address',
        'city_id',
        'alias_id',
        'state'
    ];

    // Relación con el usuario
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Relación con la ciudad
    public function city()
    {
        return $this->belongsTo(City::class, 'city_id', 'city_id');
    }

    // Relación con el alias (ej: casa, trabajo)
    public function alias()
    {
        return $this->belongsTo(Alias::class, 'alias_id', 'alias_id');
    }

    // Relación opcional con el departamento (vía ciudad)
    public function department()
    {
        return $this->hasOneThrough(
            Department::class,  // Modelo final
            City::class,        // Modelo intermedio
            'city_id',          // FK de city en user_address
            'department_id',    // FK de department en city
            'city_id',          // Local key en user_address
            'department_id'     // Local key en city
        );
    }

    // Estado activo/inactivo
    public function scopeActive($query)
    {
        return $query->where('state', true);
    }
}
