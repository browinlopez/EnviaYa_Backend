<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ResenasDeUnDomiciliarioRequest;
use App\Models\Buyer\Buyer;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;

class ResenasDeDomiciliarioController extends Controller
{
    use ComprobarPertenencia;

    public function listReviewsByDomiciliary(ResenasDeUnDomiciliarioRequest $request)
    {

        // Misma forma y mismo orden que listReviewsByBusiness, para que la
        // pantalla de calificaciones sea idéntica en los dos roles.
        $reviews = DomiciliaryReview::where('domiciliary_id', $request->domiciliary_id)
            ->with(['buyer.user', 'domiciliary.user'])
            ->orderByRaw('created_at IS NULL, created_at DESC')
            ->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'created_at' => $review->created_at,
                /*
                 * El nombre y el correo se leían de `buyer`, pero esa tabla
                 * solo guarda el vínculo: los datos de la persona están en
                 * `user`. Por eso el reseñador salía siempre en null.
                 * Se omite el email: quién califica no tiene por qué quedar
                 * expuesto ante el domiciliario.
                 */
                'user' => $review->buyer && $review->buyer->user ? [
                    'user_id' => $review->buyer->user->user_id,
                    'name' => $review->buyer->user->name,
                    'qualification' => $review->buyer->qualification,
                    'state' => $review->buyer->state,
                ] : null,
                'domiciliary' => [
                    'domiciliary_id' => $review->domiciliary->domiciliary_id,
                    'available' => $review->domiciliary->available,
                    'qualification' => $review->domiciliary->qualification,
                    'state' => $review->domiciliary->state,
                    'user' => [
                        'user_id' => $review->domiciliary->user->user_id,
                        'name' => $review->domiciliary->user->name,
                        'qualification' => $review->domiciliary->user->qualification,
                        'state' => $review->domiciliary->user->state,
                    ],
                ],
            ];
        });

        return response()->json([
            'message' => 'Reviews del domiciliario',
            'reviews' => $formatted,
        ]);
    }

    // ----------------- USER REVIEWS -----------------
}
