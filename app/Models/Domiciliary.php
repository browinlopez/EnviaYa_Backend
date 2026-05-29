<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domiciliary extends Audit
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'available', 'document', 'qualification', 'state', 'municipality_id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function municipality()
    {
        return $this->belongsTo(\App\Models\Municipality::class, 'municipality_id', 'id');
    }

    public function reviews()
    {
        return $this->hasMany(DomiciliaryReview::class, 'domiciliary_id', 'id');
    }

    public function geolocation()
    {
        return $this->hasMany(OrderGeolocation::class, 'domiciliary_id', 'id');
    }

    public function businesses()
    {
        return $this->belongsToMany(Business::class, 'business_domiciliary', 'domiciliary_id', 'business_id')
            ->withPivot('state');
    }
}
