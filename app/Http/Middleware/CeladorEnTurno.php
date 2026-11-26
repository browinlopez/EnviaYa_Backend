<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conjunto\ComplexShift;
use App\Models\Conjunto\ComplexStaff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El celador deja pasar gente solo con el turno iniciado.
 *
 * Verificar a un domiciliario, anotar una visita y marcar una salida son
 * actos de quien está en la puerta. Si el celador no ha iniciado su turno,
 * la administración no sabe quién estaba ahí cuando entró alguien: el turno
 * es lo que pone nombre y hora a cada entrada. Por eso esas acciones esperan
 * a que lo inicie. Mirar el libro o quién sigue adentro, no.
 *
 * Solo al CELADOR. El administrador del conjunto no tiene turnos (no puede
 * iniciarlos) y, si alguna vez cubre la puerta, no se le cierra.
 *
 * Va después de `conjunto` (EnsureComplexStaff), que es quien deja en la
 * petición el conjunto y el rol de quien entra.
 */
class CeladorEnTurno
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('complex_role') !== ComplexStaff::CELADOR) {
            return $next($request);
        }

        $turno = ComplexShift::abiertoDe((int) $request->user()->user_id);

        if ($turno && (int) $turno->complex_id === (int) $request->attributes->get('complex_id')) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Inicia tu turno para dejar pasar gente.',
            'reason' => 'sin_turno',
        ], 409);
    }
}
