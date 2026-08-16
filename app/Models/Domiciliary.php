<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Order\OrdersSales;
use App\Models\Reviews\DomiciliaryReview;
use Illuminate\Database\Eloquent\Model;

class Domiciliary extends Audit
{
    protected $table = 'domiciliary';
    protected $primaryKey = 'domiciliary_id';
    public $timestamps = false;

    protected $fillable = ['user_id', 'available', 'document',  'qualification', 'state'];

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

    /**
     * Pedidos asignados a este domiciliario. Se usa sobre todo para contar
     * los que tiene en curso (estado 3) y así aplicar el tope de entregas
     * simultáneas antes de asignarle uno nuevo.
     */
    public function orders()
    {
        return $this->hasMany(OrdersSales::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function businesses()
    {
        return $this->belongsToMany(Business::class, 'business_domiciliary', 'domiciliary_id', 'busines_id')
            ->withPivot('state');
    }
}
