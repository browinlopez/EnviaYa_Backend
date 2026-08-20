<?php

namespace App\Http\Controllers\Review;

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

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $user = User::select('user_id', 'rol')->where('user_id', $request->user_id)->firstOrFail();

        $orderId = $request->order_id;
        $reviewCreated = false;

        switch ($user->rol) {
            case 1: // Buyer
                $request->validate([
                    'type' => 'required|in:business,domiciliary',
                    'id' => $request->type === 'business'
                        ? 'required|integer|exists:business,busines_id'
                        : 'required|integer|exists:domiciliary,domiciliary_id',
                    'qualification' => 'required|numeric|min:1|max:5',
                    'comment' => 'nullable|string',
                ]);

                if (!$user->buyer) {
                    return response()->json([
                        'message' => 'El usuario no tiene perfil de comprador',
                    ], 422);
                }

                if ($request->type === 'business') {
                    // La columna real es busines_id (una sola "s"); con
                    // business_id el campo se descartaba del fillable y el
                    // INSERT reventaba con 500.
                    $review = BusinessReview::create([
                        'busines_id' => $request->id,
                        'buyer_id' => $user->buyer->buyer_id,
                        'qualification' => $request->qualification,
                        'comment' => $request->comment,
                        'state' => 1,
                    ]);

                    /*
                     * Y se vuelve a promediar.
                     *
                     * Este es el endpoint que usa la APP —el modal de "califica
                     * tu pedido" pega acá— y era el único camino de creación
                     * que no recalculaba. La reseña se guardaba y la estrella
                     * del negocio no se movía nunca: comprobado sobre la base
                     * local, siete reseñas activas con promedio real 4,00 y la
                     * ficha anclada en el 3,83 de antes.
                     */
                    BusinessReview::recalcularPromedio((int) $request->id);
                } else {
                    $review = DomiciliaryReview::create([
                        'domiciliary_id' => $request->id,
                        'buyer_id' => $user->buyer->buyer_id,
                        'qualification' => $request->qualification,
                        'comment' => $request->comment,
                        'state' => 1,
                    ]);

                    DomiciliaryReview::recalcularPromedio((int) $request->id);
                }
                $reviewCreated = true;
                break;

            case 3: // Domiciliario
                $request->validate([
                    'qualification' => 'required|numeric|min:1|max:5',
                    'comment' => 'nullable|string',
                ]);

                $review = UserReview::create([
                    'user_id' => $request->user_id,
                    'domiciliary_id' => $user->domiciliary->domiciliary_id,
                    'qualification' => $request->qualification,
                    'comment' => $request->comment,
                    'state' => 1,
                ]);
                $reviewCreated = true;
                break;

            default:
                return response()->json(['message' => 'Este rol no puede crear reviews'], 403);
        }

        // ✅ Actualizar la orden si se creó alguna review
        if ($reviewCreated && $orderId) {
            OrdersSales::where('orderSales_id', $orderId)->update(['has_review' => 1]);
        }

        // 🔹 Cargar solo las relaciones existentes según tipo de review
        $relations = [];
        if ($review instanceof \App\Models\Reviews\BusinessReview) {
            $relations = ['business', 'buyer.user'];
        } elseif ($review instanceof \App\Models\Reviews\DomiciliaryReview) {
            $relations = ['domiciliary', 'buyer.user'];
        } elseif ($review instanceof \App\Models\Reviews\UserReview) {
            $relations = ['user', 'domiciliary'];
        }

        // La app comprueba data.review para saber si se creó
        return response()->json([
            'message' => 'Review creada',
            'review' => $review->loadMissing($relations),
        ], 201);
    }


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
            'business_id' => 'required|integer|exists:business,busines_id'
        ]);

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


    public function createBusinessReview(Request $request)
    {
        // La columna real es busines_id (una sola "s"); se acepta business_id
        // por compatibilidad con clientes viejos pero se normaliza antes de
        // validar e insertar (con business_id el fillable lo descartaba y el
        // INSERT reventaba con "Field 'busines_id' doesn't have a default value").
        if ($request->filled('business_id') && !$request->filled('busines_id')) {
            $request->merge(['busines_id' => $request->business_id]);
        }

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
            $review = BusinessReview::create($request->only([
                'busines_id', 'buyer_id', 'qualification', 'comment', 'state',
            ]));

            $average = BusinessReview::recalcularPromedio($review->busines_id);

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

            /*
             * La columna es `busines_id`, con una sola "s". Acá decía
             * `business_id` —que no existe— y el QueryException caía en el
             * catch de abajo, que hace rollBack: editar la propia reseña
             * desde la app devolvía 500 y no cambiaba nada.
             */
            $average = BusinessReview::recalcularPromedio($review->busines_id);

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

            // Mismo error de columna que en la edición: el borrado se
            // deshacía entero por el rollBack del catch.
            $average = BusinessReview::recalcularPromedio($busines_id);

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
                    'user_id' => $review->buyer->buyer_id,
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
