<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Domiciliary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderGeolocation extends Model
{
    protected $table = 'order_geolocation';

    protected $primaryKey = 'geolocation_id';

    public $timestamps = true;

    protected $fillable = [
        'domiciliary_id',
        'orderSales_id',   // para saber a qué pedido pertenece
        'latitude',
        'longitude',
        'state',
    ];

    /** @return BelongsTo<Domiciliary, $this> */
    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /** @return BelongsTo<OrdersSales, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrdersSales::class, 'orderSales_id', 'orderSales_id');
    }
}
