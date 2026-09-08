<?php

namespace App\Http\Controllers\Order;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Services\Ajustes;
use App\Services\PoliticaDeDomicilio;
use App\Services\CustodiaDeEfectivo;
use App\Services\ConfirmacionDePago;
use App\Services\Avisos;
use App\Events\DomiciliaryLocationUpdated;
use App\Events\OrderCreated;
use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\PaymentController;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrderGeolocation;
use App\Models\Order\OrdersSales;
use App\Models\Order\OrdersSalesDetail;
use App\Models\Payment\Payment;
use App\Services\FacturaService;
use Illuminate\Support\Facades\Log;
use App\Models\Payment\PaymentForms;
use App\Models\Payment\PaymentIntent;
use App\Models\Payment\PaymentMethods;
use App\Models\Product\ProductBusiness;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Services\BoldService;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeolocalizacionDePedidoController extends Controller
{
    use ComprobarPertenencia;

    public function storeGeolocation(Request $request)
    {
        // Validar los datos recibidos
        $data = $request->validate([
            'domiciliary_id' => 'required|exists:domiciliary,domiciliary_id',
            'orderSales_id'  => 'required|exists:orderssales,orderSales_id',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'state'          => 'nullable|integer',
        ]);

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
                'error'    => $e->getMessage(),
            ]);
        }

        // Retornar respuesta JSON
        return response()->json([
            'success' => true,
            'message' => 'Geolocalización guardada correctamente',
            'data' => $geo
        ], 201);
    }
}
