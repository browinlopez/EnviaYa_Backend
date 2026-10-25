<?php

namespace App\Models\Operacion;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Una solicitud llegada desde la web pública.
 *
 * Ver la migración para por qué no viven en `pqrs`: aquello es de quien ya es
 * usuario y tiene una queja; esto es de quien todavía está fuera y quiere
 * entrar.
 */
class LandingRequest extends Model
{
    protected $table = 'landing_requests';

    public const NUEVA      = 0;
    public const EN_GESTION = 1;
    public const ATENDIDA   = 2;
    public const DESCARTADA = 3;

    /** Los tipos que acepta el enum de la tabla. */
    public const TIPOS = [
        'comercio',
        'domiciliario',
        'vecino',
        'conjunto',
        'cobertura',
        'eliminacion',
        'pqrs',
        'otro',
    ];

    /**
     * De qué motivo del formulario sale cada tipo.
     *
     * La web manda el tipo explícito, pero el motivo viaja igual en el cuerpo
     * y este mapa es el respaldo: si alguien añade un formulario allá y olvida
     * el tipo, la solicitud entra clasificada en vez de caer toda en `otro`.
     * Las claves están en minúscula y sin tildes para comparar sin sorpresas.
     */
    public const MOTIVOS = [
        'tengo un comercio'    => 'comercio',
        'quiero repartir'      => 'domiciliario',
        'soy vecino'           => 'vecino',
        'conjunto residencial' => 'conjunto',
        'eliminar mi cuenta'   => 'eliminacion',
    ];

    /**
     * Días HÁBILES que la web promete para atender una eliminación de cuenta.
     *
     * Es una promesa pública, no una meta interna: está escrita en
     * /eliminar-cuenta, que es la página que revisa Google Play. El plazo se
     * fija al recibir y no se recalcula.
     *
     * No descuenta festivos, porque la plataforma no tiene calendario de
     * festivos colombianos. Eso hace el plazo optimista por unos días al año,
     * y es preferible a fingir una precisión que no se tiene: quien atiende ve
     * la fecha y sabe que el margen real es un poco menor.
     */
    public const DIAS_ELIMINACION = 15;

    /**
     * Días HÁBILES para responder una PQRS radicada en la web.
     *
     * También es promesa pública: el formulario de /si-algo-sale-mal dice
     * "máximo 15 días hábiles", que es el plazo de la ley. El panel trabaja
     * por dentro con plazos más cortos según la prioridad; este es el techo.
     */
    public const DIAS_PQRS = 15;

    /** Lo que se acepta como foto o comprobante de una PQRS. */
    public const ADJUNTO_TIPOS = 'jpg,jpeg,png,webp,heic,pdf';
    public const ADJUNTO_MAX_KB = 8192; // 8 MB, igual que el resto de subidas

    /** Disco privado: el comprobante de una persona no va al bucket público. */
    public const ADJUNTO_DISCO = 'local';

    protected $fillable = [
        'code',
        'type',
        'origin',
        'page',
        'name',
        'contact',
        'contact_email',
        'neighborhood',
        'message',
        'payload',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'state',
        'assigned_to',
        'due_at',
        'handled_at',
        'resolution',
        'policy_version',
        'accepted_at',
        'ip',
        'user_agent',
    ];

    /*
     * La ruta del archivo no sale en el JSON del panel: el adjunto se pide por
     * su propia ruta, que comprueba el permiso. Con saber que existe basta.
     */
    protected $hidden = ['attachment_path'];

    protected $appends = ['tiene_adjunto'];

    protected $casts = [
        'payload'     => 'array',
        'state'       => 'integer',
        'assigned_to' => 'integer',
        'due_at'      => 'datetime',
        'handled_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to', 'user_id');
    }

    /**
     * Radicado consecutivo por año: SOL-2026-00042.
     *
     * Mismo criterio que el de PQRS: lleva el año porque el consecutivo se
     * reinicia, y se calcula sobre el máximo existente y no sobre el conteo,
     * porque contar después de borrar una fila devolvería un número ya usado
     * y el índice único lo rechazaría.
     */
    public static function siguienteRadicado(): string
    {
        $anio = now()->year;

        $ultimo = DB::table('landing_requests')
            ->where('code', 'like', "SOL-{$anio}-%")
            ->orderByDesc('code')
            ->value('code');

        $n = $ultimo ? ((int) substr($ultimo, -5)) + 1 : 1;

        return sprintf('SOL-%d-%05d', $anio, $n);
    }

    public function getTieneAdjuntoAttribute(): bool
    {
        return filled($this->attachment_path);
    }

    /** El tipo que corresponde a lo que mandó la web. */
    public static function tipoDesde(?string $tipo, ?string $motivo): string
    {
        // `otro` no clasifica: es lo que manda la web cuando no sabe. Se mira
        // el motivo antes de aceptarlo (la PQRS llegó así hasta tener tipo).
        if ($tipo && $tipo !== 'otro' && in_array($tipo, self::TIPOS, true)) {
            return $tipo;
        }

        $clave = self::normalizar((string) $motivo);

        // "PQRS: Reclamo", "PQRS: Queja"…: la clase va detrás y cambia.
        if (str_starts_with($clave, 'pqrs')) {
            return 'pqrs';
        }

        return self::MOTIVOS[$clave] ?? 'otro';
    }

    /** Días hábiles comprometidos por escrito para este tipo, o null. */
    public static function diasDePlazo(string $tipo): ?int
    {
        return match ($tipo) {
            'eliminacion' => self::DIAS_ELIMINACION,
            'pqrs'        => self::DIAS_PQRS,
            default       => null,
        };
    }

    /** Minúsculas y sin tildes, para comparar motivos sin sorpresas. */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
    }

    /** ¿Se pasó del plazo comprometido sin atenderse? */
    public function vencida(): bool
    {
        if (!$this->due_at || in_array($this->state, [self::ATENDIDA, self::DESCARTADA], true)) {
            return false;
        }

        return $this->due_at->isPast();
    }
}
