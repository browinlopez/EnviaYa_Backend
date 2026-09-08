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

class ResenasDeDomiciliarioController extends Controller
{
    use ComprobarPertenencia;

    public function listDomiciliaryReviews(Request $request)
    {
        $reviews = DomiciliaryReview::with('domiciliary.user', 'buyer')->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
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
                    ]
                ],
                'buyer' => [
                    'user_id'       => $review->buyer->buyer_id,
                    'qualification' => $review->buyer->qualification,
                    'state'         => $review->buyer->state,
                ]
            ];
        });

        return response()->json($formatted);
    }

    public function createDomiciliaryReview(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
            'buyer_id' => 'required|integer|exists:buyer,buyer_id',
            'qualification' => 'required|numeric|min:0|max:5',
            'comment' => 'nullable|string',
            'state' => 'boolean'
        ]);

        DB::beginTransaction();

        try {
            // Crear la reseña
            $review = DomiciliaryReview::create($request->all());

            // Recalcular el promedio de calificaciones activas
            $average = DomiciliaryReview::where('domiciliary_id', $request->domiciliary_id)
                ->where('state', true)
                ->avg('qualification');

            // Asegurar que el promedio esté entre 1.00 y 5.00
            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar el campo qualification en la tabla domiciliary
            Domiciliary::where('domiciliary_id', $request->domiciliary_id)
                ->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message' => 'Review creada y calificación actualizada',
                'review' => $review,
                'new_qualification' => $average
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la reseña',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateDomiciliaryReview(Request $request)
    {
        $request->validate([
            'review_id' => 'required|integer|exists:domiciliary_reviews,reviews_id',
            'domiciliary_id' => 'nullable|integer|exists:domiciliary,domiciliary_id',
            'buyer_id' => 'nullable|integer|exists:user,user_id',
            'qualification' => 'nullable|numeric|min:0|max:5',
            'comment' => 'nullable|string',
            'state' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $review = DomiciliaryReview::find($request->review_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            $review->update($request->only([
                'domiciliary_id',
                'buyer_id',
                'qualification',
                'comment',
                'state'
            ]));

            // Recalcular promedio de calificaciones activas
            $average = DomiciliaryReview::where('domiciliary_id', $review->domiciliary_id)
                ->where('state', true)
                ->avg('qualification');

            // Asegurar que el promedio esté entre 1.00 y 5.00
            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar calificación en la tabla domiciliario
            Domiciliary::where('domiciliary_id', $review->domiciliary_id)
                ->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message' => 'Review actualizada correctamente',
                'review' => $review,
                'new_qualification' => $average
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar la reseña',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteDomiciliaryReview(Request $request)
    {
        $request->validate([
            'review_id' => 'required|integer|exists:domiciliary_reviews,reviews_id'
        ]);

        DB::beginTransaction();

        try {
            $review = DomiciliaryReview::find($request->review_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            $domiciliary_id = $review->domiciliary_id;

            $review->delete();

            // Recalcular promedio de calificaciones activas
            $average = DomiciliaryReview::where('domiciliary_id', $domiciliary_id)
                ->where('state', true)
                ->avg('qualification');

            // Asegurar que el promedio esté entre 1.00 y 5.00
            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar calificación en la tabla domiciliario
            Domiciliary::where('domiciliary_id', $domiciliary_id)
                ->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message' => 'Review eliminada correctamente',
                'new_qualification' => $average
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar la reseña',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function listReviewsByDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id'
        ]);

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
                    ]
                ]
            ];
        });

        return response()->json([
            'message' => 'Reviews del domiciliario',
            'reviews' => $formatted
        ]);
    }

    // ----------------- USER REVIEWS -----------------
}
