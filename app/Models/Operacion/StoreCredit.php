<?php

declare(strict_types=1);

namespace App\Models\Operacion;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El cupo que una tienda le da a un comprador. Lo usado NO vive acá: es la
 * suma de sus movimientos (ver `CreditoDeTienda`).
 */
class StoreCredit extends Model
{
    protected $table = 'store_credits';

    protected $fillable = ['busines_id', 'user_id', 'credit_limit', 'created_by'];

    protected $casts = [
        'busines_id' => 'integer',
        'user_id' => 'integer',
        'credit_limit' => 'decimal:2',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return HasMany<StoreCreditMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StoreCreditMovement::class, 'store_credit_id');
    }
}
