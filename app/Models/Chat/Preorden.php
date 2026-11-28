<?php

declare(strict_types=1);

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int|null $message_id
 * @property int|null $busines_id
 * @property int|null $buyer_user_id
 * @property int $created_by
 * @property string $de
 * @property string $status
 * @property array<int, array<string, mixed>> $items
 * @property string $total
 * @property bool $ajustada
 * @property int|null $order_id
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 */
class Preorden extends Model
{
    protected $table = 'preorders';

    protected $fillable = [
        'chat_id', 'message_id', 'busines_id', 'buyer_user_id', 'created_by', 'de', 'status',
        'items', 'total', 'ajustada', 'order_id', 'confirmed_at', 'cancelled_at', 'paid_at',
    ];

    protected $casts = [
        'items' => 'array',
        'ajustada' => 'boolean',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return HasMany<PreordenEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(PreordenEvento::class, 'preorder_id');
    }
}
