<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Audit
{
    protected $table = 'chats';

    protected $primaryKey = 'chat_id';

    public $timestamps = false;

    protected $fillable = [
        'type', // private, group, etc.
    ];

    /** @return HasMany<ChatParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'chat_id', 'chat_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'chat_id', 'chat_id');
    }
}
