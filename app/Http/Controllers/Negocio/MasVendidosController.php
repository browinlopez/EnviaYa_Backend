<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LO QUE MÁS VENDE LA TIENDA.
 *
 * La app ya lo mostraba (`/product/topProductsBusiness`), pero ese endpoint
 * recibe la tienda en el cuerpo —cualquiera con sesión podía pedir la de
 * otro— y cuenta también los pedidos cancelados. Este sale de la tienda de
 * la sesión y solo cuenta lo ENTREGADO, que es lo que de verdad se vendió.
 *
 * Además de las unidades trae cuánto facturó cada producto: el que más
 * unidades mueve no siempre es el que más plata deja.
 */
class MasVendidosController extends Controller
{
    private const ENTREGADO = 4;

    public function index(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $datos = $request->validate([
            'dias'   => 'sometimes|integer|in:7,30,90,365',
            'limite' => 'sometimes|integer|min:1|max:50',
        ]);
        $dias = (int) ($datos['dias'] ?? 30);
        $limite = (int) ($datos['limite'] ?? 10);

        $filas = DB::table('orderssales_detail as d')
            ->join('orderssales as o', 'o.orderSales_id', '=', 'd.orderSales_id')
            ->join('products as p', 'p.products_id', '=', 'd.product_id')
            // El precio de HOY en la tienda, para la tarjeta del producto.
            ->leftJoin('products_business as pb', function ($j) use ($negocioId) {
                $j->on('pb.products_id', '=', 'd.product_id')->where('pb.busines_id', '=', $negocioId);
            })
            ->where('o.busines_id', $negocioId)
            ->where('o.state', self::ENTREGADO)
            ->where('o.sale_date', '>=', now()->subDays($dias)->startOfDay())
            ->groupBy('d.product_id', 'p.name', 'p.image', 'pb.price')
            ->orderByDesc('unidades')
            ->limit($limite)
            ->get([
                'd.product_id',
                'p.name',
                'p.image',
                'pb.price',
                // Lo pesado se vende en kilos: se suma lo que salió de verdad.
                DB::raw('SUM(COALESCE(d.qty_final, d.amount)) as unidades'),
                DB::raw('SUM(COALESCE(d.qty_final, d.amount) * d.unit_price) as facturado'),
                DB::raw('COUNT(DISTINCT o.orderSales_id) as pedidos'),
            ]);

        return response()->json([
            'dias'      => $dias,
            'productos' => $filas->map(fn ($f) => [
                'product_id' => (int) $f->product_id,
                'nombre'     => $f->name,
                'imagen'     => $f->image,
                'precio'     => $f->price === null ? null : (float) $f->price,
                'unidades'   => round((float) $f->unidades, 2),
                'facturado'  => round((float) $f->facturado, 2),
                'pedidos'    => (int) $f->pedidos,
            ])->values(),
        ]);
    }
}
