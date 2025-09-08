<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Reviews\DomiciliaryReview;
use Illuminate\Database\Eloquent\Model;

class Domiciliary extends Audit
{
    protected $table = 'domiciliary';
    protected $primaryKey = 'domiciliary_id';
    public $timestamps = false;

    protected $fillable = ['user_id', 'available', 'qualification', 'state'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function reviews()
    {
        return $this->hasMany(DomiciliaryReview::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function geolocation()
    {
        return $this->hasMany(OrderGeolocation::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function businesses()
    {
        return $this->belongsToMany(Business::class, 'business_domiciliary', 'domiciliary_id', 'busines_id')
            ->withPivot('state');
    }
}
