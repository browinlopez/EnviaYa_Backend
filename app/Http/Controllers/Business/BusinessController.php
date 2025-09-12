<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    // Listar todos los negocios con dueños y municipio
    public function index()
    {
        // Traemos también products y reviews
        $businesses = Business::with(['owners', 'municipality', 'products', 'reviews'])
            ->orderBy('name')
            ->get();

        $formatted = $businesses->map(function ($business) {
            return [
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
                // productos del negocio
                'products' => $business->products->map(function ($product) {
                    return [
                        'product_id'  => $product->products_id,
                        'name'        => $product->name,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'image'       => $product->image,
                        'state'       => (bool) $product->state,
                        // pivot->price solo si la relación es belongsToMany
                        'price'       => $product->pivot->price ?? null,
                    ];
                }),
                // reviews del negocio
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
            //'total' => $formatted->count(),
            'businesses' => $formatted
        ]);
    }

    public function indexByQualification()
    {
        // Traemos también products y reviews, ordenados por mejor calificación primero
        $businesses = Business::with(['owners', 'municipality', 'products', 'reviews'])
            ->orderByDesc('qualification') // 👈 aquí ordena de mayor a menor
            ->get();

        $formatted = $businesses->map(function ($business) {
            return [
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
                // productos del negocio
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
                // reviews del negocio
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
            'businesses' => $formatted
        ]);
    }

    // Crear negocio con transacción
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
            'type' => 'required|integer',
        ]);

        DB::beginTransaction();

        try {
            $business = Business::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'municipality_id' => $request->municipality_id,
                'NIT' => $request->NIT,
                'razonSocial_DCD' => $request->razonSocial_DCD,
                'logo' => $request->logo,
                'type' => $request->type,
                'state' => true
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Negocio creado correctamente',
                'business' => $business->load('municipality')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Mostrar negocio con relaciones
    public function show(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id'
        ]);

        $business = Business::with(['owners', 'products', 'reviews', 'municipality'])
            ->findOrFail($request->busines_id);

        return response()->json([
            'business_id'   => $business->busines_id,
            'name'          => $business->name,
            'phone'         => $business->phone,
            'address'       => $business->address,
            'qualification' => (float) $business->qualification,
            'razon_social'  => $business->razonSocial_DCD,
            'NIT'           => $business->NIT,
            'logo'          => $business->logo ?? 'https://example.com/default-logo.png',
            'type'           => $business->type,
            'state'         => (bool) $business->state,
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
                    'product_id' => $product->products_id,
                    'name'       => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'image'      => $product->image,
                    'state'      => (bool) $product->state,
                    'price'      => $product->pivot->price ?? null,
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
        ]);
    }

    // Actualizar negocio
    public function update(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
            'type' => 'nullable|integer|in:1,2',
            'state' => 'nullable|boolean',
            'owner_ids' => 'nullable|array',
            'owner_ids.*' => 'integer|exists:owner,owner_id'
        ]);

        DB::beginTransaction();

        try {
            $business = Business::with('owners')->findOrFail($request->busines_id);

            $business->update($request->only([
                'name',
                'phone',
                'address',
                'municipality_id',
                'NIT',
                'razonSocial_DCD',
                'logo',
                'type',
                'state'
            ]));

            if ($request->filled('owner_ids')) {
                $business->owners()->sync($request->owner_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Negocio actualizado correctamente',
                'business' => $business->load('owners', 'products', 'reviews', 'municipality')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
