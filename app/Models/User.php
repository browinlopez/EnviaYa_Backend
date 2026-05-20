<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements Auditable, MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, \OwenIt\Auditing\Auditable;
    public $timestamps = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'rol_id',
        'qualification',
        'state',
        'email_verification_token',
        'email_verified_at',
        'email_verification_expires_at'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'state' => 'boolean',
        'email_verified_at' => 'datetime',
        'email_verification_expires_at' => 'datetime',
    ];


    // Relaciones
    public function rolRelation()
    {
        return $this->belongsTo(Rol::class, 'rol_id', 'id');
    }

    public function domiciliary()
    {
        return $this->hasOne(Domiciliary::class, 'user_id', 'id');
    }

    public function buyer()
    {
        return $this->hasOne(Buyer::class, 'user_id', 'id');
    }

    public function owner()
    {
        return $this->hasOne(Owner::class, 'user_id', 'id');
    }

    public function reviewsWritten()
    {
        return $this->hasMany(UserReview::class, 'user_id', 'id');
    }

    public function businessReviews()
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'id');
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class, 'user_id', 'id');
    }

    public function favoriteBusinesses()
    {
        return $this->belongsToMany(Business::class, 'business_user_favorites', 'user_id', 'busines_id');
    }

    public function affiliatedBusinesses()
    {
        return $this->belongsToMany(
            Business::class,
            'business_user_affiliations',
            'user_id',
            'busines_id'
        );
    }

    public function getTypeAttribute()
    {
        return $this->rolRelation?->name;
        // Devuelve: "Buyer", "Owner", "Domiciliary", etc.
    }
}
