<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Audit\Audit;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Audit
{
    protected $table = 'messages';

    protected $primaryKey = 'message_id';

    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'user_id',
        'role_id',
        'content',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Relación con chat
    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }
}
