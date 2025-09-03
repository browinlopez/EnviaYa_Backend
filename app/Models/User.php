<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'user'; // 👈 tu tabla personalizada
    protected $primaryKey = 'user_id'; // 👈 tu PK
    public $timestamps = false; // 👈 porque no tienes created_at/updated_at en user

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'rol',
        'qualification',
        'state'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'state' => 'boolean',
    ];
}
