<?php

declare(strict_types=1);

namespace App\Http\Controllers\Order;

use App\Events\DomiciliaryLocationUpdated;
use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\GuardarPosicionRequest;
use App\Models\Order\OrderGeolocation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocalizacionDePedidoController extends Controller
{
    use ComprobarPertenencia;

    public function storeGeolocation(GuardarPosicionRequest $request)
    {
        // Validar los datos recibidos
        $data = $request->validated();

        // Crear registro en la base de datos
        $geo = OrderGeolocation::create($data);

        /*
         * Y se anuncia por el canal del pedido.
         *
         * El evento existía y estaba importado en este archivo desde hacía
         * tiempo, pero no se emitía en ningún sitio: el mapa en vivo no tenía
         * de dónde alimentarse y la app del comprador terminaba preguntando
         * por HTTP cada pocos segundos.
         *
         * No interrumpe la respuesta: la ubicación ya quedó guardada, y si el
         * servidor de websockets está caído el domiciliario no tiene por qué
         * enterarse ni reintentar.
         */
        try {
            broadcast(new DomiciliaryLocationUpdated($geo));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar la ubicación del domiciliario', [
                'order_id' => $geo->orderSales_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Retornar respuesta JSON
        return response()->json([
            'success' => true,
            'message' => 'Geolocalización guardada correctamente',
            'data' => $geo,
        ], 201);
    }
}
