<?php

namespace App\Models;

use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\UserReview;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, \OwenIt\Auditing\Auditable;

    protected $table = 'user';
    protected $primaryKey = 'user_id';
    public $timestamps = false;

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

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['state' => 'boolean'];

    // Relaciones
    public function rolRelation()
    {
        return $this->belongsTo(Rol::class, 'rol', 'rol_id');
    }

    public function domiciliary()
    {
        return $this->hasOne(Domiciliary::class, 'user_id', 'user_id');
    }

    public function buyer()
    {
        return $this->hasOne(Buyer::class, 'user_id', 'user_id');
    }

    public function reviewsWritten()
    {
        return $this->hasMany(UserReview::class, 'user_id', 'user_id');
    }

    public function businessReviews()
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'user_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class, 'user_id', 'user_id');
    }

    public function getTypeAttribute()
    {
        return $this->rolRelation?->name;
        // Devuelve: "Buyer", "Owner", "Domiciliary", etc.
    }
}
