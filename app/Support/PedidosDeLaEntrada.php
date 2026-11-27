<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * LO QUE LA PORTERÍA VE DE UN PEDIDO: EL NÚMERO, LA TIENDA Y LA PUERTA.
 *
 * Nunca el valor. Ni el celador ni el administrador del conjunto tienen por
 * qué saber cuánto pagó un vecino: para dejar pasar a un domiciliario basta
 * saber qué pedido trae, de qué tienda y a qué apartamento va.
 *
 * El libro de entradas guarda una copia congelada de los pedidos de cada
 * entrada. Las entradas viejas se guardaron con el total, el medio de pago y
 * el contenido con precios: por eso se limpia al LEER (las viejas) y al
 * GUARDAR (las nuevas ya no lo llevan).
 */
final class PedidosDeLaEntrada
{
    /** Los únicos campos de un pedido que salen en el libro de la portería. */
    public const CAMPOS_DEL_LIBRO = ['orderSales_id', 'tower', 'apartment', 'business_name', 'business_logo'];

    /**
     * Los pedidos congelados de una entrada, sin nada de dinero ni contenido.
     *
     * @return list<array<string, mixed>>
     */
    public static function paraElLibro(mixed $orders): array
    {
        $pedidos = is_string($orders) ? json_decode($orders, true) : $orders;

        if (! is_iterable($pedidos)) {
            return [];
        }

        $limpios = [];
        foreach ($pedidos as $p) {
            $p = (array) $p;
            $limpios[] = array_intersect_key($p, array_flip(self::CAMPOS_DEL_LIBRO));
        }

        return $limpios;
    }

    /** Lo que se guarda en `complex_entries.orders`: ya limpio. */
    public static function paraGuardar(iterable $pedidos): string
    {
        $lista = [];
        foreach ($pedidos as $p) {
            $lista[] = $p instanceof Model ? $p->toArray() : (array) $p;
        }

        return (string) json_encode(self::paraElLibro($lista), JSON_UNESCAPED_UNICODE);
    }
}
