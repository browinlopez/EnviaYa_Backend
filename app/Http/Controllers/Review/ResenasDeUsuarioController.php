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

class ResenasDeUsuarioController extends Controller
{
    use ComprobarPertenencia;
    use AyudasDeResenas;

    public function listAllUserReviews(Request $request)
    {
        $reviews = UserReview::with('user', 'domiciliary')->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                // Sin el correo: una reseña se enseña con el nombre, y estos
                // listados los puede pedir cualquier cuenta con sesión.
                'user' => [
                    'user_id'       => $review->user->user_id,
                    'name'          => $review->user->name,
                    'qualification' => $review->user->qualification,
                    'state'         => $review->user->state,
                ],
                'domiciliary' => [
                    'domiciliary_id' => $review->domiciliary->domiciliary_id,
                    'user_id' => $review->domiciliary->user_id,
                    'available' => $review->domiciliary->available,
                    'qualification' => $review->domiciliary->qualification,
                    'state' => $review->domiciliary->state,
                ]
            ];
        });

        return response()->json([
            'message' => 'Reseñas de usuarios encontradas',
            'reviews' => $formatted
        ]);
    }

    public function listUserReviewsByUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id'
        ]);

        $reviews = UserReview::with('user', 'domiciliary')
            ->where('user_id', $request->user_id)
            ->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                // Sin el correo: una reseña se enseña con el nombre, y estos
                // listados los puede pedir cualquier cuenta con sesión.
                'user' => [
                    'user_id'       => $review->user->user_id,
                    'name'          => $review->user->name,
                    'qualification' => $review->user->qualification,
                    'state'         => $review->user->state,
                ],
                'domiciliary' => [
                    'domiciliary_id' => $review->domiciliary->domiciliary_id,
                    'user_id' => $review->domiciliary->user_id,
                    'available' => $review->domiciliary->available,
                    'qualification' => $review->domiciliary->qualification,
                    'state' => $review->domiciliary->state,
                ]
            ];
        });

        return response()->json([
            'message' => 'Reviews del usuario',
            'reviews' => $formatted
        ]);
    }

    public function createUserReview(Request $request)
    {
        $request->validate([
            'user_id'        => 'required|integer|exists:user,user_id',
            'domiciliary_id' => 'nullable|integer|exists:domiciliary,domiciliary_id',
            'qualification'  => 'required|numeric|min:0|max:5',
            'comment'        => 'nullable|string',
            'state'          => 'boolean'
        ]);

        DB::beginTransaction();

        try {
            // Obtener el buyer relacionado
            $buyer = Buyer::where('user_id', $request->user_id)->firstOrFail();

            // Crear la reseña asociada al buyer
            $review = UserReview::create([
                'user_id'      => $buyer->user_id,
                'domiciliary_id' => $request->domiciliary_id,
                'qualification' => $request->qualification,
                'comment'       => $request->comment,
                'state'         => $request->state ?? true
            ]);

            // Recalcular el promedio de calificaciones activas del buyer
            $average = UserReview::where('user_id', $buyer->buyer_id)
                ->where('state', true)
                ->avg('qualification');

            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar la calificación en buyer
            $buyer->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message'           => 'Review creada y calificación del buyer actualizada',
                'review'            => $review,
                'buyer_qualification' => $average
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la reseña',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function updateUserReview(Request $request)
    {
        $request->validate([
            'review_id' => 'required|integer|exists:user_reviews,reviews_id',
            'user_id' => 'nullable|integer|exists:user,user_id',
            'domiciliary_id' => 'nullable|integer|exists:domiciliary,domiciliary_id',
            'qualification' => 'nullable|numeric|min:0|max:5',
            'comment' => 'nullable|string',
            'state' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $review = UserReview::find($request->review_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            $review->update($request->only([
                'user_id',
                'domiciliary_id',
                'qualification',
                'comment',
                'state'
            ]));

            // Recalcular promedio de calificaciones activas
            $average = UserReview::where('user_id', $review->user_id)
                ->where('state', true)
                ->avg('qualification');

            // Asegurar que el promedio esté entre 1.00 y 5.00
            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar calificación en la tabla user
            User::where('user_id', $review->user_id)
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

    public function deleteUserReview(Request $request)
    {
        $request->validate([
            'review_id' => 'required|integer|exists:user_reviews,reviews_id'
        ]);

        DB::beginTransaction();

        try {
            $review = UserReview::find($request->review_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            /*
             * SOLO QUIEN LA ESCRIBIO.
             *
             * Bastaba el numero de la resena —correlativo— para cambiarle la
             * nota a cualquiera o borrarla. Una calificacion que el calificado
             * puede borrar no es una calificacion.
             */
            if (!$this->esSuyaLaResena($request, $review->user_id, 'user')) {
                DB::rollBack();

                return response()->json(['message' => 'Esa resena no es tuya.'], 403);
            }

            $user_id = $review->user_id;

            $review->delete();

            // Recalcular promedio de calificaciones activas
            $average = UserReview::where('user_id', $user_id)
                ->where('state', true)
                ->avg('qualification');

            // Asegurar que el promedio esté entre 1.00 y 5.00
            $average = max(1.00, min(round($average, 2), 5.00));

            // Actualizar calificación en la tabla user
            User::where('user_id', $user_id)
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
}
