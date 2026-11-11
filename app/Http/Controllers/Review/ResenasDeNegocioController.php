<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ResenasDeUnNegocioRequest;
use App\Models\Domiciliary;
use App\Models\Reviews\BusinessReview;

class ResenasDeNegocioController extends Controller
{
    use AyudasDeResenas;
    use ComprobarPertenencia;

    public function listReviewsByBusiness(ResenasDeUnNegocioRequest $request)
    {

        // Las más recientes primero; las que no tienen fecha (reseñas viejas,
        // creadas cuando el modelo no guardaba timestamps) quedan al final.
        /*
         * Solo las activas. El promedio ya se calculaba con `state = true`,
         * pero el listado devolvía todas, así que la app promediaba por su
         * cuenta sobre reseñas ocultas y la ficha del negocio mostraba dos
         * notas distintas en la misma pantalla.
         */
        $reviews = BusinessReview::with('business', 'buyer.user')
            ->where('busines_id', $request->business_id)
            ->where('state', true)
            ->orderByRaw('created_at IS NULL, created_at DESC')
            ->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'created_at' => $review->created_at,
                // Endpoint público: no exponer el email del reseñador.
                'user' => $review->buyer && $review->buyer->user ? [
                    'user_id' => $review->buyer->user->user_id,
                    'name' => $review->buyer->user->name,
                    'qualification' => $review->buyer->qualification,
                    'state' => $review->buyer->state,
                ] : null,
                'business' => $review->business ? [
                    // `business_id` no existe en la tabla: devolvía null.
                    'business_id' => $review->business->busines_id,
                    'name' => $review->business->name,
                    'phone' => $review->business->phone,
                    'address' => $review->business->address,
                    'qualification' => $review->business->qualification,
                    'razon_social' => $review->business->razonSocial_DCD,
                    'NIT' => $review->business->NIT,
                    'logo' => $review->business->logo,
                    'city' => $review->business->city,
                    'state' => $review->business->state,
                ] : null,
            ];
        });

        return response()->json($formatted);
    }

    // ----------------- DOMICILIARY REVIEWS -----------------
}
