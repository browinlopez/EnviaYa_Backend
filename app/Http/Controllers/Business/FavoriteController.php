<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserFavorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    public function toggleFavorite(Request $request)
    {
        $request->validate([
            'user_id'    => 'required|integer|exists:user,user_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $userId     = $request->user_id;
        $businessId = $request->busines_id;

        $favorite = BusinessUserFavorite::where('user_id', $userId)
            ->where('busines_id', $businessId)
            ->first();

        if ($favorite) {
            // ya existe, eliminarlo
            $favorite->delete();
            return response()->json(['message' => 'Eliminado de favoritos']);
        } else {
            // no existe, crearlo
            BusinessUserFavorite::create([
                'user_id'    => $userId,
                'busines_id' => $businessId,
            ]);
            return response()->json(['message' => 'Agregado a favoritos']);
        }
    }


    // Listar favoritos del usuario
    public function myFavorites(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $userId = $request->user_id;

        // Traemos favoritos con todas las relaciones del negocio
        $favorites = BusinessUserFavorite::where('user_id', $userId)
            ->with([
                'business.owners',
                'business.municipality',
                'business.products',
                'business.reviews'
            ])
            ->get();

        // Transformamos a un array similar al index
        $formatted = $favorites->map(function ($favorite) {
            $business = $favorite->business;

            return [
                'favorite_id'   => $favorite->id,
                'business_id'   => $business->busines_id,
                'name'          => $business->name,
                'phone'         => $business->phone,
                'address'       => $business->address,
                'qualification' => (float) $business->qualification,
                'razon_social'  => $business->razonSocial_DCD,
                'NIT'           => $business->NIT,
                'logo'          => $business->logo ?? 'https://example.com/default-logo.png',
                'state'         => (bool) $business->state,
                'type'          => $business->type,
                'municipality'  => $business->municipality ? [
                    'id'   => $business->municipality->id,
                    'name' => $business->municipality->name,
                ] : null,
                'owner_count'   => $business->owners->count(),
                'owners'        => $business->owners->map(function ($owner) {
                    return [
                        'owner_id'          => $owner->owner_id,
                        'user_id'           => $owner->user_id,
                        'profile_photo'     => $owner->profile_photo ?? 'https://example.com/default-user.png',
                        'document_type'     => $owner->document_type,
                        'document_number'   => $owner->document_number,
                        'birthdate'         => $owner->birthdate,
                        'contact_secondary' => $owner->contact_secondary,
                        'notes'             => $owner->notes,
                        'state'             => (bool) $owner->state,
                    ];
                }),
                'products' => $business->products->map(function ($product) {
                    return [
                        'product_id'  => $product->products_id,
                        'name'        => $product->name,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'image'       => $product->image,
                        'state'       => (bool) $product->state,
                        'price'       => $product->pivot->price ?? null,
                    ];
                }),
                'reviews' => $business->reviews->map(function ($review) {
                    return [
                        'review_id'  => $review->reviews_id ?? null,
                        'buyer_id'   => $review->buyer_id,
                        'rating'     => (float) $review->qualification ?? 0,
                        'comment'    => $review->comment ?? '',
                        'created_at' => $review->created_at ?? null,
                    ];
                }),
            ];
        });

        return response()->json([
            'favorites' => $formatted
        ]);
    }
}
