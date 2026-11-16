<?php

declare(strict_types=1);

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Review\ResenasDeUnDomiciliarioRequest;
use App\Http\Resources\Resena\ResenaDeUnDomiciliarioResource;
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

        $formatted = $reviews->map(
            fn ($review) => (new ResenaDeUnDomiciliarioResource($review))->resolve(),
        );

        return response()->json([
            'message' => 'Reviews del domiciliario',
            'reviews' => $formatted,
        ]);
    }

    // ----------------- USER REVIEWS -----------------
}
