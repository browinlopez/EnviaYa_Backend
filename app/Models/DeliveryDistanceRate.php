<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryDistanceRate extends Model
{
    use HasFactory;

    protected $table = 'delivery_distance_rates';

    protected $fillable = [
        'min_distance_meters',
        'max_distance_meters',
        'price_cop',
        'active'
    ];

    protected $casts = [
        'active' => 'boolean',
        'min_distance_meters' => 'integer',
        'max_distance_meters' => 'integer',
        'price_cop' => 'float',
    ];

    /**
     * Obtener el precio para una distancia en metros dada.
     */
    public static function getPriceForDistance(int $meters): ?float
    {
        $rate = self::where('active', true)
            ->where('min_distance_meters', '<=', $meters)
            ->where('max_distance_meters', '>=', $meters)
            ->first();

        return $rate ? $rate->price_cop : null;
    }
}
