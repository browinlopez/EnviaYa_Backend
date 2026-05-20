<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderGeolocation extends Model
{
    protected $table = 'order_geolocation';
    protected $primaryKey = 'geolocation_id';
    public $timestamps = true;

    protected $fillable = [
        'domiciliary_id',
        'order_sale_id',   // para saber a qué pedido pertenece
        'latitude',
        'longitude',
        'state'
    ];

    public function domiciliary()
    {
        return $this->belongsTo(\App\Models\Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function order()
    {
        return $this->belongsTo(\App\Models\OrderSale::class, 'order_sale_id', 'order_sale_id');
    }
}
