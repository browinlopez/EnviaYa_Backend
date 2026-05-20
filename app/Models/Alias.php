<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alias extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'aliases';

    protected $fillable = [
        'name'
    ];

    // Relación con las direcciones de usuarios
    public function userAddresses()
    {
        return $this->hasMany(UserAddress::class, 'alias_id', 'id');
    }
}
