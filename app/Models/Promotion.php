<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Order\OrderPromotion;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promotion extends Audit
{
    use HasFactory;

    /** Escrita y guardada, todavía sin salir. */
    public const BORRADOR = 0;

    /** Ya salió a los clientes. */
    public const ENVIADA = 1;

    /*
     * El tendero la retiró.
     *
     * NO SE BORRA LA FILA, y esa es la parte que importa. El tope diario cuenta
     * lo que salió hoy: si borrar quitara la fila, el tope se esquivaría solo
     * con mandar, borrar, mandar, borrar… y los clientes recibirían cinco
     * notificaciones seguidas de la misma tienda. Retirar quita el aviso de la
     * campana de la gente; el gasto del día ya está gastado.
     */
    public const RETIRADA = 2;

    /* ---------------- LOS TIPOS DE REGLA ---------------- */

    /** Solo avisa. No toca ningún precio. */
    public const SIN_DESCUENTO = 'ninguno';

    /** «20% de descuento». El valor va en `percentage_discount`. */
    public const PORCENTAJE = 'porcentaje';

    /** «Lleva 3, paga 2». Los números van en `lleva` y `paga`. */
    public const NXM = 'nxm';

    public const TIPOS = [self::SIN_DESCUENTO, self::PORCENTAJE, self::NXM];

    protected $table = 'promotions';
    protected $primaryKey = 'promotion_id';

    /*
     * La tabla nació sin `created_at` porque nunca se usó. Ahora sí importa:
     * la lista del tendero se ordena por lo más reciente, y sin fecha el orden
     * sería el de los identificadores, que coincide hoy y deja de coincidir en
     * cuanto algo se inserte fuera de orden.
     */
    public $timestamps = true;

    protected $fillable = [
        'busines_id',
        'code_promotions',
        'description',
        'percentage_discount',
        'start_date',
        'end_date',
        'state',
        'sent_at',
        'recipients_count',
        'created_by',
        'tipo',
        'lleva',
        'paga',
    ];

    protected $casts = [
        'percentage_discount' => 'float',
        'lleva'               => 'integer',
        'paga'                => 'integer',
        'sent_at'          => 'datetime',
        'start_date'       => 'datetime',
        'end_date'         => 'datetime',
        'recipients_count' => 'integer',
        'state'            => 'integer',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function orders()
    {
        return $this->hasMany(OrderPromotion::class, 'promotion_id', 'promotion_id');
    }

    /** Los productos a los que se refiere la frase. Puede estar vacía. */
    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'promotion_products',
            'promotion_id',
            'products_id',
            'promotion_id',
            'products_id',
        );
    }

    /**
     * Lo que ve el cliente y lo que ve el tendero en su lista.
     *
     * Se arma acá y no en el controlador para que la promoción se vea igual en
     * los tres sitios donde aparece: la lista del tendero, la respuesta al
     * crearla y el aviso que le llega al comprador.
     */
    public function toApi(): array
    {
        return [
            'promotion_id'     => $this->promotion_id,
            'busines_id'       => $this->busines_id,
            'texto'            => $this->description,
            'productos'        => $this->relationLoaded('products')
                ? $this->products->map(fn ($p) => [
                    'products_id' => $p->products_id,
                    'name'        => $p->name,
                    'image'       => $p->image ?? null,
                ])->values()
                : [],
            'enviada_el'       => $this->sent_at?->toIso8601String(),
            'destinatarios'    => (int) $this->recipients_count,
            'state'            => (int) $this->state,
            'hasta'            => $this->end_date?->toIso8601String(),
            'hasta_texto'      => $this->hastaEnPalabras(),
            'vencida'          => $this->vencida(),
            'retirada'         => (int) $this->state === self::RETIRADA,
            'tipo'             => $this->tipo ?? self::SIN_DESCUENTO,
            'porcentaje'       => $this->tipo === self::PORCENTAJE
                ? round(((float) $this->percentage_discount) * 100)
                : null,
            'lleva'            => $this->lleva,
            'paga'             => $this->paga,
            'regla_texto'      => $this->reglaEnPalabras(),
        ];
    }

    /** Ya pasó su fecha. Sin fecha, no vence nunca. */
    public function vencida(): bool
    {
        return $this->end_date !== null && $this->end_date->isPast();
    }

    /** ¿Esta promoción rebaja precios, o solo avisa? */
    public function descuenta(): bool
    {
        return $this->tipo !== self::SIN_DESCUENTO && $this->tipo !== null;
    }

    /** Está enviada, no retirada y no vencida: se puede aplicar hoy. */
    public function vigente(): bool
    {
        return (int) $this->state === self::ENVIADA && !$this->vencida();
    }

    /**
     * CUÁNTO REBAJA sobre una línea del carrito.
     *
     * @param  int    $cantidad  Unidades de ese producto en el carrito.
     * @param  float  $precio    Precio unitario, el del servidor.
     */
    public function descuentoSobre(int $cantidad, float $precio): float
    {
        if (!$this->descuenta() || $cantidad < 1 || $precio <= 0) {
            return 0.0;
        }

        if ($this->tipo === self::PORCENTAJE) {
            return round($cantidad * $precio * (float) $this->percentage_discount, 2);
        }

        // `nxm`: por cada grupo completo de `lleva`, se regalan
        // `lleva - paga` unidades. Con 7 unidades y un 3x2 van dos grupos
        // completos —6— y sobra una que se paga: se regalan 2.
        $lleva = (int) $this->lleva;
        $paga  = (int) $this->paga;

        if ($lleva < 2 || $paga < 1 || $paga >= $lleva) {
            // Una regla imposible no regala nada. Se comprueba también al
            // crearla, pero una fila vieja o tocada a mano no puede tumbar
            // el cálculo de un pedido.
            return 0.0;
        }

        $gratis = intdiv($cantidad, $lleva) * ($lleva - $paga);

        return round($gratis * $precio, 2);
    }

    /**
     * La regla en palabras, para la app.
     *
     * La arma el servidor y no la app por lo de siempre: la lee el tendero
     * al escribirla y el cliente al recibirla, y contada de dos maneras
     * distintas parecen dos promociones distintas.
     */
    public function reglaEnPalabras(): ?string
    {
        if (!$this->descuenta()) {
            return null;
        }

        if ($this->tipo === self::PORCENTAJE) {
            return round(((float) $this->percentage_discount) * 100) . '% de descuento';
        }

        return "Lleva {$this->lleva}, paga {$this->paga}";
    }

    /**
     * CUÁNTAS UNIDADES HACEN FALTA para que la promoción se note.
     *
     * Un 3x2 con dos unidades en el carrito no rebaja nada, y el cliente
     * necesita saberlo ANTES de pagar: «te falta una para que salga gratis»
     * es la diferencia entre una promoción que funciona y una que enfada.
     */
    public function minimoParaAplicar(): int
    {
        return $this->tipo === self::NXM ? max(2, (int) $this->lleva) : 1;
    }

    /**
     * «Solo hoy», «Hasta el domingo», «Hasta el 14 de septiembre».
     *
     * Se arma acá y no en la app porque lo leen los dos lados —el tendero en su
     * lista y el cliente en su campana— y una fecha contada de dos maneras
     * distintas se lee como dos promociones distintas.
     */
    public function hastaEnPalabras(): ?string
    {
        if ($this->end_date === null) {
            return null;
        }

        $fin = $this->end_date->copy()->setTimezone('America/Bogota');
        $hoy = now('America/Bogota');

        if ($fin->isSameDay($hoy)) {
            return 'Solo hoy';
        }

        if ($fin->isSameDay($hoy->copy()->addDay())) {
            return 'Hasta mañana';
        }

        // Dentro de la semana se dice el día: «hasta el domingo» se entiende
        // sin mirar el calendario, y «hasta el 14/09» no.
        if ($fin->diffInDays($hoy) < 7) {
            return 'Hasta el ' . $fin->locale('es')->dayName;
        }

        return 'Hasta el ' . $fin->locale('es')->isoFormat('D [de] MMMM');
    }
}
