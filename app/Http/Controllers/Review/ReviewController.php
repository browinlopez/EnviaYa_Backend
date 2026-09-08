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

class ReviewController extends Controller
{
    use ComprobarPertenencia;

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
}
