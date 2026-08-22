<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

class EmailVerificationController extends Controller
{
    public function __invoke(EmailVerificationRequest $request)
    {
        $request->fulfill();

        /*
         * El destino sale de la configuración y no escrito acá.
         *
         * Apuntaba a `https://vecipaya.com/verificado`: un dominio que ya no
         * responde y, además, una ruta que la landing no tenía. El último paso
         * del registro —justo después de que la persona hizo lo que le
         * pedimos— terminaba en una página de error.
         */
        return redirect(config('services.sitio.url') . '/verificado');
    }
}

