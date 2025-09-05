<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public function index()
    {
        $businesses = Business::with('owners')->get();

        $formatted = $businesses->map(function ($business) {
            return [
                'business_id' => $business->busines_id,
                'name' => $business->name,
                'phone' => $business->phone,
                'address' => $business->address,
                'qualification' => $business->qualification,
                'razon_social' => $business->razonSocial_DCD,
                'NIT' => $business->NIT,
                'logo' => $business->logo,
                'city' => $business->city,
                'state' => $business->state,
                'owners' => $business->owners->map(function ($owner) {
                    return [
                        'owner_id' => $owner->owner_id,
                        'user_id' => $owner->user_id,
                        'profile_photo' => $owner->profile_photo,
                        'document_type' => $owner->document_type,
                        'document_number' => $owner->document_number,
                        'birthdate' => $owner->birthdate,
                        'contact_secondary' => $owner->contact_secondary,
                        'notes' => $owner->notes,
                        'state' => $owner->state,
                    ];
                }),
            ];
        });

        return response()->json($formatted);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $business = Business::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'city' => $request->city,
                'NIT' => $request->NIT,
                'razonSocial_DCD' => $request->razonSocial_DCD,
                'logo' => $request->logo,
                'state' => true
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Negocio creado correctamente',
                'business' => $business
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id'
        ]);

        $business = Business::with('owners', 'products', 'reviews')
            ->findOrFail($request->busines_id);

        return response()->json([
            'business_id' => $business->busines_id,
            'name' => $business->name,
            'phone' => $business->phone,
            'address' => $business->address,
            'qualification' => $business->qualification,
            'razon_social' => $business->razonSocial_DCD,
            'NIT' => $business->NIT,
            'logo' => $business->logo,
            'city' => $business->city,
            'state' => $business->state,
            'owners' => $business->owners->map(function ($owner) {
                return [
                    'owner_id' => $owner->owner_id,
                    'user_id' => $owner->user_id,
                    'profile_photo' => $owner->profile_photo,
                    'document_type' => $owner->document_type,
                    'document_number' => $owner->document_number,
                    'birthdate' => $owner->birthdate,
                    'contact_secondary' => $owner->contact_secondary,
                    'notes' => $owner->notes,
                    'state' => $owner->state,
                ];
            }),
            'products' => $business->products->map(function ($product) {
                return [
                    'product_id' => $product->products_id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'image' => $product->image,
                    'state' => $product->state,
                    'price' => $product->pivot->price ?? null,
                ];
            }),
            'reviews' => $business->reviews, // Puedes formatear esto también si lo deseas
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
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
                'city',
                'NIT',
                'razonSocial_DCD',
                'logo',
                'state'
            ]));

            if ($request->filled('owner_ids')) {
                $business->owners()->sync($request->owner_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Negocio actualizado correctamente',
                'business' => $business->load('owners', 'products', 'reviews')
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
