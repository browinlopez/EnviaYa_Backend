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

class ResenasDeNegocioController extends Controller
{
    use ComprobarPertenencia;
    use AyudasDeResenas;

    public function listBusinessReviews()
    {
        /*
         * `buyer.user`, no `user`.
         *
         * `BusinessReview` no tiene relación `user`: se llega a la persona por
         * el comprador. Tal como estaba, este listado respondía 500 en TODAS
         * las llamadas —Eloquent revienta al no encontrar la relación—, o sea
         * que nunca funcionó.
         */
        $reviews = BusinessReview::with('business', 'buyer.user')->get();

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
                /*
                 * Sin el correo de quien reseñó.
                 *
                 * Esto lo puede pedir cualquier cuenta con sesión, y una reseña
                 * se muestra con el nombre: el correo no lo pinta nadie y
                 * entregarlo convierte el listado en una lista de direcciones
                 * de todos los clientes. Es la misma fuga que ya se cerró en la
                 * ficha del negocio y en el listado del inicio.
                 */
                'user' => $review->buyer?->user ? [
                    'user_id'       => $review->buyer->user->user_id,
                    'name'          => $review->buyer->user->name,
                    'qualification' => $review->buyer->user->qualification,
                    'state'         => $review->buyer->user->state,
                ] : null,
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

            /*
             * SOLO QUIEN LA ESCRIBIO.
             *
             * Bastaba el numero de la resena —correlativo— para cambiarle la
             * nota a cualquiera o borrarla. Una calificacion que el calificado
             * puede borrar no es una calificacion.
             */
            if (!$this->esSuyaLaResena($request, $review->buyer_id, 'buyer')) {
                DB::rollBack();

                return response()->json(['message' => 'Esa resena no es tuya.'], 403);
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

            /*
             * SOLO QUIEN LA ESCRIBIO.
             *
             * Bastaba el numero de la resena —correlativo— para cambiarle la
             * nota a cualquiera o borrarla. Una calificacion que el calificado
             * puede borrar no es una calificacion.
             */
            if (!$this->esSuyaLaResena($request, $review->buyer_id, 'buyer')) {
                DB::rollBack();

                return response()->json(['message' => 'Esa resena no es tuya.'], 403);
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
}
