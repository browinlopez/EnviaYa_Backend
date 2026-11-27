<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string $method
 * @property string $path
 * @property string $fingerprint
 * @property int|null $status_code
 * @property string|null $response_body
 * @property string|null $response_content_type
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'user_id',
        'idempotency_key',
        'method',
        'path',
        'fingerprint',
        'status_code',
        'response_body',
        'response_content_type',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'status_code' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
