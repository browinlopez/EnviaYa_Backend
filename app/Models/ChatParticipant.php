<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatParticipant extends Audit
{
    protected $table = 'chat_participants';
    protected $primaryKey = 'participant_id';
       public $timestamps = false;

    protected $fillable = ['chat_id', 'user_id', 'role_id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function role()
    {
        return $this->belongsTo(Rol::class, 'role_id', 'rol_id');
    }
}
