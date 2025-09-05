<?php

namespace App\Models\Chat;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Model;

class Chat extends Audit
{
    protected $table = 'chats';
    protected $primaryKey = 'chat_id';
    public $timestamps = false;

    protected $fillable = [
        'type' // private, group, etc.
    ];

    public function participants()
    {
        return $this->hasMany(ChatParticipant::class, 'chat_id', 'chat_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class, 'chat_id', 'chat_id');
    }
}
