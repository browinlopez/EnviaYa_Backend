<?php

declare(strict_types=1);

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ResenasDeUnNegocioRequest;
use App\Http\Resources\Resena\ResenaDeUnNegocioResource;
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

        $formatted = $reviews->map(
            fn ($review) => (new ResenaDeUnNegocioResource($review))->resolve(),
        );

        return response()->json($formatted);
    }

    // ----------------- DOMICILIARY REVIEWS -----------------
}
