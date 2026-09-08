<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\Reviews\UserReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auxiliares que usan varios controladores del panel.
 *
 * Estaban sueltos dentro del controlador gordo. Aca no se duplican:
 * quien los necesite usa el trait.
 */
trait AyudasDeResenas
{
    /**
     * Si la resena la escribio quien pregunta.
     *
     * `business_reviews` guarda el `buyer_id` y `user_reviews` el `user_id`,
     * que no son lo mismo: el primero es la fila de comprador y el segundo la
     * cuenta. De ahi el segundo parametro.
     *
     * El equipo interno tambien puede: moderar contenido es parte de su
     * trabajo, y su puerta esta comprobada aparte.
     */
    private function esSuyaLaResena(Request $request, $duenio, string $tipo): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        if ((int) $user->rol === 4) {
            return true;
        }

        if ($tipo === 'user') {
            return (int) $duenio === (int) $user->user_id;
        }

        return $duenio !== null
            && (int) $duenio === (int) ($user->buyer?->buyer_id ?? 0);
    }
}
