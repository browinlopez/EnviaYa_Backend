<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Promotion;
use App\Services\PromocionesDeLaTienda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LAS PROMOCIONES QUE ESCRIBE EL TENDERO
 *
 * Dos rutas y nada más: ver las que ha mandado y mandar una. No hay editar ni
 * borrar a propósito — una promoción enviada ya está en el teléfono de sus
 * clientes, y un botón de «borrar» que no la quita de ahí es una mentira en la
 * pantalla. Lo que se puede hacer con una promoción equivocada es mandar otra,
 * igual que en la vida real.
 *
 * El negocio NO viene en el cuerpo: lo pone el middleware `negocio` a partir de
 * quién pide. Es lo que impide que alguien mande una promoción en nombre de la
 * tienda de al lado, con el nombre de esa tienda en la barra de notificaciones
 * de sus clientes.
 */
class PromocionesController extends Controller
{
    public function __construct(private PromocionesDeLaTienda $promociones)
    {
    }

    /**
     * Su historial, y de paso lo que necesita la pantalla para abrirse.
     *
     * `le_quedan_hoy` y `destinatarios` van acá y no en un endpoint aparte
     * porque la pantalla los necesita ANTES de que escriba nada: el botón dice
     * «Enviar a mis 34 clientes» desde el primer momento, y si ya gastó el cupo
     * del día conviene decírselo antes de que redacte, no después.
     */
    public function index(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');

        /*
         * Las retiradas NO salen en la lista.
         *
         * La fila sigue ahí —el tope diario la cuenta—, pero enseñarla sería
         * confuso: el tendero la retiró justamente para que desapareciera, y
         * verla en su historial parecería que no funcionó.
         */
        $lista = Promotion::with('products')
            ->where('busines_id', $businessId)
            ->where('state', '!=', Promotion::RETIRADA)
            ->orderByDesc('promotion_id')
            ->limit(50)
            ->get()
            ->map(fn (Promotion $p) => $p->toApi());

        return response()->json([
            'promociones'   => $lista,
            'destinatarios' => count($this->promociones->destinatarios($businessId)),
            'le_quedan_hoy' => $this->promociones->leQuedanHoy($businessId),
            'maximo_letras' => PromocionesDeLaTienda::MAXIMO_CARACTERES,
        ]);
    }

    /** Las reglas del texto, el plazo y los productos. Iguales al crear y al corregir. */
    private function reglas(): array
    {
        return [
            'texto' => 'required|string|min:5|max:' . PromocionesDeLaTienda::MAXIMO_CARACTERES,
            'productos' => 'sometimes|array|max:20',
            'productos.*' => 'integer|exists:products,products_id',
            'hasta' => 'sometimes|nullable|in:' . implode(',', PromocionesDeLaTienda::PLAZOS),

            /*
             * LA REGLA, que es lo que convierte el aviso en un descuento.
             *
             * `porcentaje` viaja como entero de 1 a 70. No de 0 a 100: un
             * 100% es regalar el producto, y un 0% es una promoción que no
             * promociona. El tope de 70 es la barrera contra el error de dedo
             * —escribir 90 queriendo 9— sobre algo que cobra de su bolsillo.
             */
            'tipo' => 'sometimes|in:' . implode(',', Promotion::TIPOS),
            'porcentaje' => 'required_if:tipo,' . Promotion::PORCENTAJE . '|nullable|integer|min:1|max:70',
            'lleva' => 'required_if:tipo,' . Promotion::NXM . '|nullable|integer|min:2|max:12',
            'paga' => 'required_if:tipo,' . Promotion::NXM . '|nullable|integer|min:1|lt:lleva',
        ];
    }

    private function mensajes(): array
    {
        return [
            'texto.required' => 'Escribe qué quieres ofrecer.',
            'texto.min'      => 'Escribe un poco más para que se entienda.',
            'texto.max'      => 'La promoción es muy larga; en el teléfono se cortaría.',
            'porcentaje.max' => 'Un descuento mayor al 70% suele ser un error de dedo. Escríbelo en el texto si de verdad es así.',
            'paga.lt'        => 'Tiene que pagar menos de lo que lleva; si no, no hay promoción.',
        ];
    }

    /** La regla tal como se guarda, a partir de lo que mandó la pantalla. */
    private function regla(Request $request): array
    {
        $tipo = $request->input('tipo', Promotion::SIN_DESCUENTO);

        if ($tipo === Promotion::PORCENTAJE) {
            return [
                'tipo' => $tipo,
                // Se guarda como fracción (0.20) y no como 20: es lo que
                // esperaba la columna desde 2025 y lo que multiplica directo.
                'percentage_discount' => ((int) $request->input('porcentaje')) / 100,
                'lleva' => null,
                'paga'  => null,
            ];
        }

        if ($tipo === Promotion::NXM) {
            return [
                'tipo'  => $tipo,
                'percentage_discount' => null,
                'lleva' => (int) $request->input('lleva'),
                'paga'  => (int) $request->input('paga'),
            ];
        }

        return [
            'tipo' => Promotion::SIN_DESCUENTO,
            'percentage_discount' => null,
            'lleva' => null,
            'paga'  => null,
        ];
    }

    /**
     * Que los productos sean DE SU TIENDA.
     *
     * `exists:products` solo comprueba que existan en el catálogo maestro, que
     * es de toda la plataforma. Sin esto, un tendero podría anunciar algo que
     * no vende: sus clientes tocarían el aviso y no llegarían a ninguna parte.
     *
     * @param  list<int>  $productos
     */
    private function sonSuyos(int $businessId, array $productos): bool
    {
        if ($productos === []) {
            return true;
        }

        $suyos = DB::table('products_business')
            ->where('busines_id', $businessId)
            ->whereIn('products_id', $productos)
            ->pluck('products_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_diff($productos, $suyos) === [];
    }

    /** Escribirla y mandarla. Es la misma acción: no hay borradores. */
    public function store(Request $request)
    {
        $businessId = (int) $request->attributes->get('busines_id');
        $negocio = $request->attributes->get('negocio');

        $request->validate($this->reglas(), $this->mensajes());

        if ($this->promociones->leQuedanHoy($businessId) < 1) {
            return response()->json([
                'message' => 'Ya enviaste tus promociones de hoy. Puedes mandar otra mañana.',
                'reason'  => 'limite_diario',
            ], 422);
        }

        $productos = array_map('intval', $request->input('productos', []));

        if (!$this->sonSuyos($businessId, $productos)) {
            return response()->json([
                'message' => 'Alguno de esos productos no es de tu tienda.',
                'reason'  => 'producto_ajeno',
            ], 422);
        }

        $destinatarios = $this->promociones->destinatarios($businessId);

        if (count($destinatarios) > PromocionesDeLaTienda::MAXIMO_DESTINATARIOS) {
            // Ver la cabecera del servicio: pasado este tamaño el envío directo
            // agota el tiempo de espera y hay que moverlo a la cola.
            return response()->json([
                'message' => 'Tu tienda tiene demasiados clientes para enviar desde acá. Escríbenos y lo hacemos por ti.',
                'reason'  => 'demasiados_destinatarios',
            ], 422);
        }

        $negocio = $negocio instanceof Business
            ? $negocio
            : Business::findOrFail($businessId);

        $promocion = $this->promociones->publicar(
            $negocio,
            $request->string('texto')->toString(),
            $productos,
            (int) $request->user()->user_id,
            $request->input('hasta'),
            $this->regla($request),
        );

        return response()->json([
            'message'    => count($destinatarios) === 0
                // Se dice claro en vez de cantar «enviada» sobre cero personas:
                // el tendero creería que su promoción salió y no entendería por
                // qué no vende más.
                ? 'Guardamos tu promoción, pero todavía no tienes clientes afiliados que la reciban.'
                : 'Tu promoción salió a ' . count($destinatarios) . ' cliente' . (count($destinatarios) === 1 ? '' : 's') . '.',
            'promocion'     => $promocion->toApi(),
            'le_quedan_hoy' => $this->promociones->leQuedanHoy($businessId),
        ], 201);
    }

    /**
     * La promoción de ESTA tienda, o 404.
     *
     * Un 404 y no un 403 a propósito: responder «prohibido» sobre el número de
     * otra tienda confirmaría que esa promoción existe, y probando números se
     * podría averiguar cuántas manda el vecino.
     */
    private function suya(Request $request, int $id): ?Promotion
    {
        return Promotion::with('products')
            ->where('busines_id', (int) $request->attributes->get('busines_id'))
            ->where('promotion_id', $id)
            ->first();
    }

    /**
     * Corregirla.
     *
     * Arregla el aviso en la campana de cada cliente. NO vuelve a sonar el
     * teléfono: el zumbido ya salió, y hacerlo sonar otra vez por la misma
     * promoción molesta más que el error de dedo que se venía a corregir.
     */
    public function update(Request $request, int $id)
    {
        $businessId = (int) $request->attributes->get('busines_id');
        $promocion = $this->suya($request, $id);

        if (!$promocion) {
            return response()->json(['message' => 'Esa promoción no es de tu tienda.'], 404);
        }

        if ((int) $promocion->state === Promotion::RETIRADA) {
            return response()->json([
                'message' => 'Esa promoción ya la retiraste. Escribe una nueva.',
                'reason'  => 'ya_retirada',
            ], 422);
        }

        $request->validate($this->reglas(), $this->mensajes());

        $productos = array_map('intval', $request->input('productos', []));

        if (!$this->sonSuyos($businessId, $productos)) {
            return response()->json([
                'message' => 'Alguno de esos productos no es de tu tienda.',
                'reason'  => 'producto_ajeno',
            ], 422);
        }

        $corregida = $this->promociones->corregir(
            $promocion,
            $request->string('texto')->toString(),
            $productos,
            $request->input('hasta'),
            $this->regla($request),
        );

        return response()->json([
            'message'   => 'Corregimos tu promoción. A quien ya la recibió le aparecerá el texto nuevo.',
            'promocion' => $corregida->toApi(),
        ]);
    }

    /**
     * Retirarla.
     *
     * Le quita el aviso de la campana a todos. No devuelve el cupo del día: ese
     * ya se gastó cuando los teléfonos sonaron, y devolverlo dejaría mandar y
     * retirar en bucle hasta hartar a los clientes.
     */
    public function destroy(Request $request, int $id)
    {
        $promocion = $this->suya($request, $id);

        if (!$promocion) {
            return response()->json(['message' => 'Esa promoción no es de tu tienda.'], 404);
        }

        $this->promociones->retirar($promocion);

        return response()->json([
            'message' => 'La retiramos. Ya no le aparece a tus clientes.',
        ]);
    }
}
