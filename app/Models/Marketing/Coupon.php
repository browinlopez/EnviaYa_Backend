<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Código de descuento.
 */
class Coupon extends Model
{
    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'max_discount',
        'min_order',
        'max_uses',
        'max_uses_per_user',
        'business_id',
        'category_id',
        'advertiser_id',
        'starts_at',
        'ends_at',
        'state',
    ];

    protected $casts = [
        'value'             => 'decimal:2',
        'max_discount'      => 'decimal:2',
        'min_order'         => 'decimal:2',
        'max_uses'          => 'integer',
        'max_uses_per_user' => 'integer',
        'uses_count'        => 'integer',
        'business_id'       => 'integer',
        'category_id'       => 'integer',
        'advertiser_id'     => 'integer',
        'starts_at'         => 'date',
        'ends_at'           => 'date',
        'state'             => 'integer',
    ];

    /**
     * El código siempre en mayúsculas y sin espacios.
     *
     * Se normaliza al escribir y no al leer para que el índice único de la
     * columna sirva de verdad: si entraran "verano25" y "VERANO25" serían dos
     * filas distintas y el mismo código daría dos descuentos.
     */
    public function setCodeAttribute($valor): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $valor));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class, 'coupon_id');
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class, 'advertiser_id');
    }

    /**
     * Columnas calificadas: el listado del panel une `business` y `category`,
     * y ambas tienen `state`. Sin calificar, la consulta es ambigua en MySQL.
     */
    public function scopeVigente(Builder $q): Builder
    {
        $hoy = now()->toDateString();

        return $q->where($q->qualifyColumn('state'), 1)
            ->whereDate($q->qualifyColumn('starts_at'), '<=', $hoy)
            ->whereDate($q->qualifyColumn('ends_at'), '>=', $hoy);
    }

    /** ¿Se agotó el cupo global? */
    public function agotado(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    /**
     * Descuento que aplica a un subtotal dado, ya con el tope respetado.
     *
     * Nunca devuelve más que el propio subtotal: un fijo de 10.000 sobre un
     * pedido de 8.000 dejaría el total en negativo y el cobro fallaría después,
     * lejos de acá y sin explicación.
     */
    public function descuentoPara(float $subtotal): float
    {
        $bruto = $this->type === 'percent'
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null) {
            $bruto = min($bruto, (float) $this->max_discount);
        }

        return round(min($bruto, $subtotal), 2);
    }
}
