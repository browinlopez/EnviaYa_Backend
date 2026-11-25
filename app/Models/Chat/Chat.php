<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Audit\Audit;
use App\Models\Business;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Audit
{
    protected $table = 'chats';

    protected $primaryKey = 'chat_id';

    public $timestamps = false;

    protected $fillable = [
        'type', // private, group, etc.

        /*
         * DE QUE MOSTRADOR ES ESTA CONVERSACION.
         *
         * Nulo cuando no es de ninguno: un domiciliario hablando de una
         * entrega, o soporte respondiendo un reclamo. Nulo ahi es una
         * respuesta --«esto no es de un mostrador»--, no un hueco.
         */
        'busines_id',
    ];

    /**
     * El negocio del chat, si es con una tienda.
     *
     * @return BelongsTo<Business, $this>
     */
    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

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
