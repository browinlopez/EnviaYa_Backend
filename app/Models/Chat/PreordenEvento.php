<?php

declare(strict_types=1);

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $preorder_id
 * @property int|null $user_id
 * @property string $accion
 * @property array<string, mixed>|null $antes
 * @property array<string, mixed>|null $despues
 */
class PreordenEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'preorder_events';

    protected $fillable = ['preorder_id', 'user_id', 'accion', 'antes', 'despues'];

    protected $casts = [
        'antes' => 'array',
        'despues' => 'array',
    ];
}
