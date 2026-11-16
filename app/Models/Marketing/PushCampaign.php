<?php

declare(strict_types=1);

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

/**
 * Envío masivo de notificaciones a un segmento de usuarios.
 */
class PushCampaign extends Model
{
    protected $table = 'push_campaigns';

    public const BORRADOR = 0;

    public const PROGRAMADA = 1;

    public const ENVIADA = 2;

    public const CANCELADA = 3;

    /**
     * Los estados que se pueden PONER a mano desde el panel.
     *
     * `ENVIADA` no esta, y no es un olvido: la pone el proceso que manda
     * los avisos cuando los ha mandado. Si se pudiera escribir a mano, se
     * daria por enviada una campana que nadie recibio.
     *
     * Por eso esta constante NO es la lista completa de estados: es
     * exactamente lo que `CampanaPushRequest` valida, que escribia
     * `in:0,1,3` a mano. La diferencia con las demas se descubrio al
     * comparar las dos listas antes de cablearlas.
     */
    public const ESTADOS_A_MANO = [self::BORRADOR, self::PROGRAMADA, self::CANCELADA];

    protected $fillable = [
        'title',
        'body',
        'link_type',
        'link_value',
        'segment',
        'scheduled_at',
        'state',
        'created_by',
    ];

    protected $casts = [
        'segment' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
        'state' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * Una campaña ya enviada no se toca.
     *
     * Editar el texto de algo que la gente ya recibió en el teléfono deja el
     * registro contando una historia distinta de la que ocurrió, y es la clase
     * de diferencia que solo aparece cuando alguien reclama.
     */
    public function editable(): bool
    {
        return in_array($this->state, [self::BORRADOR, self::PROGRAMADA], true)
            && $this->sent_at === null;
    }
}
