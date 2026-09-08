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

class MediosDePagoController extends Controller
{
    use ComprobarPertenencia;

    // Obtener métodos de pago
    public function paymentMethods()
    {
        $methods = PaymentMethods::with('forms')
            ->where('state', 1)
            ->get();

        return response()->json($methods);
    }

    // Obtener formas de pago
    public function paymentForms()
    {
        $forms = PaymentForms::with('methods')
            ->where('state', 1)
            ->get();

        return response()->json($forms);
    }
}
