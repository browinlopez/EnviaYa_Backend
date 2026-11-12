<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Audit\Audit;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Audit
{
    protected $table = 'chat_participants';

    protected $primaryKey = 'participant_id';

    public $timestamps = false;

    protected $fillable = ['chat_id', 'user_id', 'role_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function role()
    {
        return $this->belongsTo(Rol::class, 'role_id', 'rol_id');
    }
}
