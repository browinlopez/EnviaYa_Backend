<?php

namespace App\Http\Controllers\Review;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\Reviews\UserReview;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    // ----------------- BUSINESS REVIEWS -----------------

    public function listBusinessReviews()
    {
        $reviews = BusinessReview::with('business', 'buyer', 'user')->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'business' => [
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
                ],
                'user' => [
                    'user_id' => $review->user->user_id,
                    'name' => $review->user->name,
                    'email' => $review->user->email,
                    'qualification' => $review->user->qualification,
                    'state' => $review->user->state,
                ]
            ];
        });

        return response()->json($formatted);
    }

    public function listReviewsByBusiness(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $reviews = BusinessReview::with('business', 'user')
            ->where('busines_id', $request->busines_id)
            ->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'user' => [
                    'user_id' => $review->user->user_id,
                    'name' => $review->user->name,
                    'email' => $review->user->email,
                    'qualification' => $review->user->qualification,
                    'state' => $review->user->state,
                ],
                'business' => [
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
                ]
            ];
        });

        return response()->json($formatted);
    }

    public function createBusinessReview(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
            'buyer_id' => 'required|integer|exists:buyer,buyer_id',
            'qualification' => 'required|numeric|min:0|max:5',
            'comment' => 'nullable|string',
            'state' => 'boolean'
        ]);

        DB::beginTransaction();

        try {
            // Crear la reseña
            $review = BusinessReview::create($request->all());

            // Recalcular el promedio de calificaciones activas
            $average = BusinessReview::where('busines_id', $request->busines_id)
                ->where('state', true)
                ->avg('qualification');

            // Limitar el promedio a máximo 5.00
            $average = min(round($average, 2), 5.00);

            // Actualizar el campo qualification en la tabla business
            Business::where('busines_id', $request->busines_id)
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

    public function updateBusinessReview(Request $request)
    {
        $request->validate([
            'reviews_id' => 'required|integer|exists:business_reviews,reviews_id',
            'qualification' => 'nullable|numeric|min:0|max:5',
            'comment' => 'nullable|string',
            'state' => 'nullable|boolean'
        ]);

        DB::beginTransaction();

        try {
            $review = BusinessReview::find($request->reviews_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            $review->update($request->only(['qualification', 'comment', 'state']));

            // Recalcular promedio de calificaciones activas
            $average = BusinessReview::where('busines_id', $review->busines_id)
                ->where('state', true)
                ->avg('qualification');

            $average = min(round($average, 2), 5.00);

            Business::where('busines_id', $review->busines_id)
                ->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message' => 'Review actualizada',
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


    public function deleteBusinessReview(Request $request)
    {
        $request->validate([
            'reviews_id' => 'required|integer|exists:business_reviews,reviews_id',
        ]);

        DB::beginTransaction();

        try {
            $review = BusinessReview::find($request->reviews_id);
            if (!$review) {
                throw new \Exception('Review no encontrada');
            }

            $busines_id = $review->busines_id;

            $review->delete();

            // Recalcular promedio después de eliminar
            $average = BusinessReview::where('busines_id', $busines_id)
                ->where('state', true)
                ->avg('qualification');

            $average = min(round($average, 2), 5.00);

            Business::where('busines_id', $busines_id)
                ->update(['qualification' => $average]);

            DB::commit();

            return response()->json([
                'message' => 'Review eliminada',
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

    // ----------------- DOMICILIARY REVIEWS -----------------

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
                        'email' => $review->domiciliary->user->email,
                        'qualification' => $review->domiciliary->user->qualification,
                        'state' => $review->domiciliary->user->state,
                    ]
                ],
                'buyer' => [
                    'user_id' => $review->buyer->user_id,
                    'name' => $review->buyer->name,
                    'email' => $review->buyer->email,
                    'qualification' => $review->buyer->qualification,
                    'state' => $review->buyer->state,
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

        $reviews = DomiciliaryReview::where('domiciliary_id', $request->domiciliary_id)
            ->with(['buyer', 'domiciliary.user'])
            ->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'buyer' => [
                    'user_id' => $review->buyer->user_id,
                    'name' => $review->buyer->name,
                    'email' => $review->buyer->email,
                    'qualification' => $review->buyer->qualification,
                    'state' => $review->buyer->state,
                ],
                'domiciliary' => [
                    'domiciliary_id' => $review->domiciliary->domiciliary_id,
                    'available' => $review->domiciliary->available,
                    'qualification' => $review->domiciliary->qualification,
                    'state' => $review->domiciliary->state,
                    'user' => [
                        'user_id' => $review->domiciliary->user->user_id,
                        'name' => $review->domiciliary->user->name,
                        'email' => $review->domiciliary->user->email,
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

    public function listAllUserReviews(Request $request)
    {
        $reviews = UserReview::with('user', 'domiciliary')->get();

        $formatted = $reviews->map(function ($review) {
            return [
                'review_id' => $review->reviews_id,
                'qualification' => $review->qualification,
                'comment' => $review->comment,
                'state' => $review->state,
                'user' => [
                    'user_id' => $review->user->user_id,
                    'name' => $review->user->name,
                    'email' => $review->user->email,
                    'qualification' => $review->user->qualification,
                    'state' => $review->user->state,
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
                'user' => [
                    'user_id' => $review->user->user_id,
                    'name' => $review->user->name,
                    'email' => $review->user->email,
                    'qualification' => $review->user->qualification,
                    'state' => $review->user->state,
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
