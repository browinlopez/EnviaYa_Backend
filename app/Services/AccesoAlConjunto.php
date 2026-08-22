<?php

namespace App\Services;

use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La entrada de un domiciliario a un conjunto.
 *
 * DOS FORMAS DE IDENTIFICARSE, CON DISTINTA CONFIANZA:
 *
 *   · Un código de cinco minutos que genera su app. Prueba que quien está en
 *     la puerta tiene la sesión abierta en ese teléfono ahora mismo.
 *   · La cédula, escrita por el celador. Solo prueba que alguien dijo un
 *     número. Se admite porque un teléfono sin batería no puede dejar a nadie
 *     sin entregar, pero queda anotado cuál de las dos se usó.
 *
 * EL CÓDIGO VIVE EN CACHÉ, NO EN LA BASE. Es un dato que caduca solo y que
 * nadie va a consultar después: una tabla obligaría a limpiarla, y una fila
 * caducada olvidada es justo lo que convierte un código temporal en uno
 * permanente.
 */
class AccesoAlConjunto
{
    /** Cinco minutos: lo que se tarda en llegar de la moto a la portería. */
    public const VIGENCIA_SEGUNDOS = 300;

    private function llave(string $codigo): string
    {
        return 'acceso_conjunto:' . $codigo;
    }

    /**
     * Un código nuevo para este domiciliario.
     *
     * Se invalida el anterior: si valieran varios a la vez, uno filtrado
     * seguiría sirviendo aunque la persona ya hubiera pedido otro.
     */
    public function generar(Domiciliary $domiciliario): array
    {
        $previo = Cache::pull('acceso_conjunto_de:' . $domiciliario->domiciliary_id);

        if ($previo) {
            Cache::forget($this->llave($previo));
        }

        // Sin caracteres ambiguos: este código se dicta en voz alta cuando la
        // cámara del celador no lee bien.
        $codigo = strtoupper(Str::random(8));

        Cache::put($this->llave($codigo), $domiciliario->domiciliary_id, self::VIGENCIA_SEGUNDOS);
        Cache::put('acceso_conjunto_de:' . $domiciliario->domiciliary_id, $codigo, self::VIGENCIA_SEGUNDOS);

        return [
            'code'       => $codigo,
            'expires_in' => self::VIGENCIA_SEGUNDOS,
            'expires_at' => now()->addSeconds(self::VIGENCIA_SEGUNDOS)->toIso8601String(),
        ];
    }

    /** El domiciliario detrás de un código vigente, o null. */
    /**
     * Quién es el dueño de este código. DE UN SOLO USO.
     *
     * `pull` y no `get`: lee y borra en la misma operación. Antes sólo leía, y
     * el código servía todas las veces que hiciera falta durante sus cinco
     * minutos. Dos problemas, y el segundo apareció al construir el tablero:
     *
     *  · Quien alcanzara a ver el QR por encima del hombro entraba también.
     *    Los cinco minutos acotan cuánto dura el riesgo, no que exista.
     *
     *  · Cada verificación anotaba OTRA entrada. Verificar dos veces el mismo
     *    código —porque el celador dudó, o porque se recargó la pantalla—
     *    dejaba dos filas idénticas, y el conteo de entradas del conjunto
     *    contaba visitas que no ocurrieron.
     *
     * Es el mismo argumento que ya justificaba invalidar el anterior al
     * generar uno nuevo: si valieran varios a la vez, uno filtrado seguiría
     * sirviendo. Que valga varias veces es la otra mitad del mismo agujero.
     *
     * También se olvida el índice inverso, o el próximo `generar()` intentaría
     * borrar una llave que ya no está.
     */
    public function resolverCodigo(string $codigo): ?Domiciliary
    {
        $limpio = strtoupper(trim($codigo));
        $id     = Cache::pull($this->llave($limpio));

        if (!$id) {
            return null;
        }

        Cache::forget('acceso_conjunto_de:' . $id);

        return Domiciliary::find($id);
    }

    /** El domiciliario por su cédula, o null. */
    public function resolverCedula(string $cedula): ?Domiciliary
    {
        return Domiciliary::where('document', trim($cedula))->first();
    }

    /**
     * Los pedidos que este domiciliario lleva HACIA ESE CONJUNTO ahora mismo.
     *
     * Es la funcionalidad entera: si no lleva ninguno, no tiene por qué entrar.
     *
     * Se mira la DIRECCIÓN DE ENTREGA del pedido y no el conjunto del
     * comprador. Alguien puede vivir en un conjunto y pedir a la oficina, o al
     * revés; lo que le da derecho a entrar es a dónde va el paquete.
     */
    /**
     * Los pedidos que este domiciliario lleva a ESTE conjunto.
     *
     * Devuelve además de dónde viene cada uno y qué trae dentro.
     *
     * SOBRE EL CONTENIDO: lo pidió expresamente el producto, para que el
     * celador pueda comprobar que lo que entra coincide con lo que se anunció.
     * Va aparte —`items`— y la interfaz lo enseña plegado, porque no hace
     * falta para dejar entrar y hay negocios donde sí es delicado: este mismo
     * conjunto recibe pedidos de una droguería, y la lista de medicamentos de
     * un apartamento no es asunto de la portería. Quien lo despliega deja
     * constancia de que lo hizo a propósito.
     */
    public function pedidosEnElConjunto(Domiciliary $domiciliario, int $complexId)
    {
        $pedidos = OrdersSales::query()
            ->join('user_address as ua', 'ua.address_id', '=', 'orderssales.address_id')
            ->leftJoin('business as b', 'b.busines_id', '=', 'orderssales.busines_id')
            ->where('orderssales.domiciliary_id', $domiciliario->domiciliary_id)
            // En camino: ya lo recogió y todavía no lo entregó.
            ->where('orderssales.state', 3)
            ->where('ua.complex_id', $complexId)
            ->get([
                'orderssales.orderSales_id',
                'orderssales.total',
                'orderssales.methods_id',
                'ua.tower',
                'ua.apartment',
                'b.name as business_name',
                'b.logo as business_logo',
            ]);

        if ($pedidos->isEmpty()) {
            return $pedidos;
        }

        $detalles = DB::table('orderssales_detail as d')
            ->join('products as p', 'p.products_id', '=', 'd.product_id')
            ->whereIn('d.orderSales_id', $pedidos->pluck('orderSales_id'))
            ->get(['d.orderSales_id', 'd.amount', 'd.unit_price', 'p.name'])
            ->groupBy('orderSales_id');

        return $pedidos->map(function ($o) use ($detalles) {
            $items = $detalles->get($o->orderSales_id, collect());

            $o->items = $items->map(fn ($i) => [
                'name'       => $i->name,
                'amount'     => (int) $i->amount,
                'unit_price' => (float) $i->unit_price,
            ])->values();

            $o->items_count = $items->sum('amount');

            return $o;
        });
    }
}
