<?php

namespace App\Services;

use App\Models\Promotion;

/**
 * LO QUE REBAJAN LAS PROMOCIONES DE LA TIENDA SOBRE UN CARRITO
 *
 * Las promociones nacieron como un aviso escrito que el tendero cumplía en el
 * mostrador. Los tenderos pidieron que se aplicaran de verdad, así que ahora
 * cada una lleva una regla —un porcentaje o un «lleva N paga M»— y esto es lo
 * que la convierte en pesos.
 *
 * TRES REGLAS DE LA CASA, y cada una evita un desastre distinto:
 *
 *  1. NO SE ACUMULAN. Si dos promociones alcanzan al mismo producto se aplica
 *     LA MEJOR PARA EL CLIENTE y solo esa. Sumarlas es como se regala el
 *     inventario: dos promociones del 60 % sobre el mismo atún lo dejan gratis
 *     y encima con vuelto.
 *
 *  2. SE CALCULA EN EL SERVIDOR, SIEMPRE. La app no manda descuentos ni
 *     totales: los pide con `/orders/cotizar` y los recibe ya hechos. Es la
 *     misma lección que dejó la tarifa de domicilio, que vivía escrita en dos
 *     sitios y prometía $2.000 mientras se cobraban $14.000.
 *
 *  3. UNA PROMOCIÓN SIN PRODUCTOS ALCANZA A TODO EL CARRITO. «20 % en toda la
 *     tienda hoy» no es de ningún producto en particular, y obligar a elegir
 *     uno sería obligar a mentir.
 *
 * Solo entran las VIGENTES: enviadas, no retiradas y no vencidas. Una
 * promoción de ayer no puede rebajar el pedido de hoy.
 */
class DescuentoPorPromocion
{
    /**
     * @param  iterable  $lineas   Lo pedido: [{product_id, amount}, ...]
     * @param  \Illuminate\Support\Collection  $precios  products_id => precio del servidor
     * @return array{
     *   descuento: float,
     *   aplicadas: list<array{promotion_id:int,texto:string,regla:?string,ahorro:float}>,
     *   cerca: list<array{promotion_id:int,texto:string,faltan:int,producto:string}>
     * }
     */
    public function paraElCarrito(int $negocioId, iterable $lineas, $precios): array
    {
        $promociones = Promotion::with('products')
            ->where('busines_id', $negocioId)
            ->where('state', Promotion::ENVIADA)
            ->get()
            ->filter(fn (Promotion $p) => $p->vigente() && $p->descuenta());

        if ($promociones->isEmpty()) {
            return ['descuento' => 0.0, 'aplicadas' => [], 'cerca' => []];
        }

        /*
         * Se recorre PRODUCTO por PRODUCTO y no promoción por promoción.
         *
         * Es lo que permite la regla de no acumular: para cada línea del
         * carrito se mira cuál de las promociones que la alcanzan rebaja más,
         * y se queda esa. Al revés —recorriendo promociones— habría que llevar
         * la cuenta de qué producto ya se descontó, y ese es justo el sitio
         * donde se cuela un doble descuento.
         */
        $ahorroPorPromocion = [];
        $cerca = [];
        $total = 0.0;

        foreach ($lineas as $linea) {
            $productoId = (int) ($linea['product_id'] ?? 0);
            $cantidad   = (int) ($linea['amount'] ?? 0);
            $precio     = (float) ($precios[$productoId] ?? 0);

            if ($cantidad < 1 || $precio <= 0) {
                continue;
            }

            $mejor = null;
            $mejorAhorro = 0.0;

            foreach ($promociones as $promocion) {
                if (!$this->alcanza($promocion, $productoId)) {
                    continue;
                }

                $ahorro = $promocion->descuentoSobre($cantidad, $precio);

                if ($ahorro > $mejorAhorro) {
                    $mejor = $promocion;
                    $mejorAhorro = $ahorro;
                }

                /*
                 * «Te falta una para que salga gratis».
                 *
                 * Un 3x2 con dos unidades en el carrito no rebaja nada, y sin
                 * decirlo el cliente paga las dos convencido de que la
                 * promoción no sirve. Se anota para que la app pueda avisar.
                 */
                if ($ahorro <= 0 && $cantidad < $promocion->minimoParaAplicar()) {
                    $cerca[$promocion->promotion_id] = [
                        'promotion_id' => (int) $promocion->promotion_id,
                        'texto'        => (string) $promocion->description,
                        'faltan'       => $promocion->minimoParaAplicar() - $cantidad,
                        'producto'     => $this->nombreDe($promocion, $productoId),
                    ];
                }
            }

            if ($mejor === null || $mejorAhorro <= 0) {
                continue;
            }

            $total += $mejorAhorro;

            $id = (int) $mejor->promotion_id;
            $ahorroPorPromocion[$id] = [
                'promotion_id' => $id,
                'texto'        => (string) $mejor->description,
                'regla'        => $mejor->reglaEnPalabras(),
                'ahorro'       => round(($ahorroPorPromocion[$id]['ahorro'] ?? 0) + $mejorAhorro, 2),
            ];
        }

        return [
            'descuento' => round($total, 2),
            'aplicadas' => array_values($ahorroPorPromocion),
            // Solo se avisa de lo que NO se aplicó ya por otra vía.
            'cerca'     => array_values(array_diff_key($cerca, $ahorroPorPromocion)),
        ];
    }

    /** ¿Esta promoción cubre este producto? Sin productos, cubre todo. */
    private function alcanza(Promotion $promocion, int $productoId): bool
    {
        if ($promocion->products->isEmpty()) {
            return true;
        }

        return $promocion->products->contains(
            fn ($p) => (int) $p->products_id === $productoId,
        );
    }

    private function nombreDe(Promotion $promocion, int $productoId): string
    {
        return (string) (
            $promocion->products->firstWhere('products_id', $productoId)?->name
            ?? 'ese producto'
        );
    }
}
