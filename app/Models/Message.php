<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // Relación con chat
    public function chat()
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }
}
