<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'user_address';

    protected $fillable = [
        'user_id',
        'address',
        'municipality_id',
        'alias_id',
        'latitude',
        'longitude',
        'state'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class, 'municipality_id', 'id');
    }

    public function alias()
    {
        return $this->belongsTo(Alias::class, 'alias_id', 'id');
    }

    public function department()
    {
        return $this->hasOneThrough(
            Department::class,
            Municipality::class,
            'id',
            'id',
            'municipality_id',
            'department_id'
        );
    }

    public function country()
    {
        return $this->hasOneThrough(
            Country::class,
            Department::class,
            'id',
            'id',
            'department_id',
            'country_id'
        );
    }

    public function scopeActive($query)
    {
        return $query->where('state', true);
    }
}
