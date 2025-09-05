<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Owner\Owner;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerController extends Controller
{
    public function index()
    {
        $owners = Owner::with('businesses')->get();

        $formatted = $owners->map(function ($owner) {
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
                'businesses' => $owner->businesses->map(function ($business) {
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
            'email' => 'required|email|unique:user,email',
            'password' => 'required|string|min:6',
            'profile_photo' => 'nullable|string',
            'document_type' => 'nullable|string',
            'document_number' => 'nullable|string',
            'birthdate' => 'nullable|date',
            'contact_secondary' => 'nullable|string',
            'notes' => 'nullable|string',
            'business_ids' => 'nullable|array',
            'business_ids.*' => 'integer|exists:business,busines_id'
        ]);

        DB::beginTransaction();

        try {
            // Crear usuario
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'rol' => 2, // rol de owner
                'state' => true
            ]);

            // Crear owner
            $owner = Owner::create([
                'user_id' => $user->user_id,
                'state' => true,
                'profile_photo' => $request->profile_photo,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'birthdate' => $request->birthdate,
                'contact_secondary' => $request->contact_secondary,
                'notes' => $request->notes
            ]);

            // Relacionar negocios si se envían
            if ($request->filled('business_ids')) {
                $owner->businesses()->sync($request->business_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Owner creado correctamente',
                'owner' => $owner->load('user', 'businesses')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request)
    {
        $request->validate([
            'owner_id' => 'required|integer|exists:owner,owner_id'
        ]);

        $owner = Owner::with('businesses')->findOrFail($request->owner_id);

        return response()->json([
            'owner_id' => $owner->owner_id,
            'user_id' => $owner->user_id,
            'profile_photo' => $owner->profile_photo,
            'document_type' => $owner->document_type,
            'document_number' => $owner->document_number,
            'birthdate' => $owner->birthdate,
            'contact_secondary' => $owner->contact_secondary,
            'notes' => $owner->notes,
            'state' => $owner->state,
            'businesses' => $owner->businesses->map(function ($business) {
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
                ];
            }),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'owner_id' => 'required|integer|exists:owner,owner_id',
            'state' => 'nullable|boolean',
            'profile_photo' => 'nullable|string',
            'document_type' => 'nullable|int',
            'document_number' => 'nullable|string',
            'birthdate' => 'nullable|date',
            'contact_secondary' => 'nullable|string',
            'notes' => 'nullable|string',
            'name' => 'nullable|string',
            'email' => 'nullable|email|unique:user,email,' . $request->owner_id . ',user_id',
            'password' => 'nullable|string|min:6',
            'business_ids' => 'nullable|array',
            'business_ids.*' => 'integer|exists:business,busines_id'
        ]);

        DB::beginTransaction();

        try {
            $owner = Owner::with('user')->findOrFail($request->owner_id);

            // Actualizar campos del owner
            $owner->update($request->only([
                'state',
                'profile_photo',
                'document_type',
                'document_number',
                'birthdate',
                'contact_secondary',
                'notes'
            ]));

            // Actualizar campos del usuario relacionado
            $userData = $request->only(['name', 'email', 'password']);
            if (isset($userData['password'])) {
                $userData['password'] = bcrypt($userData['password']);
            }
            $owner->user->update($userData);

            // Actualizar negocios si vienen en request
            if ($request->filled('business_ids')) {
                $owner->businesses()->sync($request->business_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Owner actualizado correctamente',
                'owner' => $owner->load('user', 'businesses')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el owner',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
