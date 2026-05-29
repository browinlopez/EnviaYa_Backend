<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessUserAffiliation;
use App\Models\User;
use Illuminate\Http\Request;

class AffiliationController extends Controller
{
    // Afiliar o desafiliar
    public function toggle(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'business_id' => 'required|integer|exists:business,business_id',
        ]);

        $userId = $request->user_id;
        $businessId = $request->business_id;

        $affiliation = BusinessUserAffiliation::where('user_id', $userId)
            ->where('business_id', $businessId)
            ->first();

        if ($affiliation) {
            $affiliation->delete();
            return response()->json(['message' => 'Usuario desafiliado']);
        } else {
            BusinessUserAffiliation::create([
                'user_id' => $userId,
                'business_id' => $businessId,
            ]);
            return response()->json(['message' => 'Usuario afiliado']);
        }
    }
    // Listar usuarios afiliados a una tienda
    public function listUsers(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,business_id',
        ]);

        $businessId = $request->business_id;

        // 🔹 Cargar afiliaciones con información del usuario + relaciones
        $affiliations = BusinessUserAffiliation::where('business_id', $businessId)
            ->with([
                'business:business_id,name',
                'user' => function ($query) {
                    $query->select('user_id', 'name', 'email', 'phone', 'roles', 'qualification', 'state')
                        ->with([
                            'buyer:buyer_id,user_id,qualification,state,belongs_to_complex',
                            'domiciliary:domiciliary_id,user_id,available,document,qualification,state',
                            'rolRelation:rol_id,name'
                        ]);
                },
            ])
            ->get();

        // 🔹 Dar formato a la respuesta para que sea más clara
        $formatted = $affiliations->map(function ($aff) {
            return [
                'affiliation_id' => $aff->id ?? null,
                'business' => [
                    'id' => $aff->business->business_id ?? null,
                    'name' => $aff->business->name ?? null,
                ],
                'user' => [
                    'id' => $aff->user->id ?? null,
                    'name' => $aff->user->name ?? null,
                    'email' => $aff->user->email ?? null,
                    'phone' => $aff->user->phone ?? null,
                    'roles' => $aff->user->rolRelation->name ?? null,
                    'qualification' => $aff->user->qualification ?? null,
                    'state' => $aff->user->state ?? null,
                    'buyer' => $aff->user->buyer ? [
                        'buyer_id' => $aff->user->buyer->buyer_id,
                        'qualification' => $aff->user->buyer->qualification,
                        'belongs_to_complex' => $aff->user->buyer->belongs_to_complex,
                        'state' => $aff->user->buyer->state,
                    ] : null,
                    'domiciliary' => $aff->user->domiciliary ? [
                        'domiciliary_id' => $aff->user->domiciliary->domiciliary_id,
                        'document' => $aff->user->domiciliary->document,
                        'available' => $aff->user->domiciliary->available,
                        'qualification' => $aff->user->domiciliary->qualification,
                        'state' => $aff->user->domiciliary->state,
                    ] : null,
                ],
            ];
        });

        return response()->json([
            'affiliations' => $formatted,
            'count' => $formatted->count(),
        ]);
    }
    //Buscar comprador por número de teléfono y rol
    public function searchBuyerByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:7',
        ]);

        $phone = $request->phone;

        $user = User::with(['buyer'])
            ->where('phone', $phone)
            ->where('roles', 1) // Filtrar por rol igual a 1
            ->whereHas('buyer') // Solo los que tienen relación con comprador
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No se encontró comprador con ese número y rol'], 404);
        }

        return response()->json([
            'user' => $user,
            'buyer' => $user->buyer,
        ]);
    }
    //Afiliacion movil usuarios
    public function AfiliationUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'business_id' => 'required|integer|exists:business,business_id',
        ]);

        $userId = $request->user_id;
        $businessId = $request->business_id;

        // 1️⃣ Verificar si ya está afiliado a este negocio
        $alreadyAffiliated = BusinessUserAffiliation::where('user_id', $userId)
            ->where('business_id', $businessId)
            ->exists();

        if ($alreadyAffiliated) {
            return response()->json([
                'message' => 'El usuario ya está afiliado a este negocio'
            ], 409);
        }

        // 2️⃣ Contar afiliaciones actuales del usuario
        $affiliationsCount = BusinessUserAffiliation::where('user_id', $userId)->count();

        if ($affiliationsCount >= 3) {
            return response()->json([
                'message' => 'El usuario ya alcanzó el máximo de 3 negocios afiliados'
            ], 422);
        }

        // 3️⃣ Crear afiliación
        $affiliation = BusinessUserAffiliation::create([
            'user_id' => $userId,
            'business_id' => $businessId,
        ]);

        return response()->json([
            'message' => 'Usuario afiliado correctamente',
            'affiliation' => $affiliation
        ], 201);
    }
    //desafiliar usuario movil
    public function DesafiliationUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'business_id' => 'required|integer|exists:business,business_id',
        ]);

        $affiliation = BusinessUserAffiliation::where('user_id', $request->user_id)
            ->where('business_id', $request->business_id)
            ->first();

        if (!$affiliation) {
            return response()->json([
                'message' => 'El usuario no está afiliado a este negocio'
            ], 404);
        }

        $affiliation->delete();

        return response()->json([
            'message' => 'Usuario desafiliado correctamente'
        ]);
    }

    // Listar todos los usuarios afiliados a un negocio
    public function getAffiliatedUsers(Request $request)
    {
        $request->validate([
            'business_id' => 'required|integer|exists:business,business_id',
        ]);

        $businessId = $request->business_id;

        $affiliations = BusinessUserAffiliation::where('business_id', $businessId)
            ->with([
                'user' => function ($q) {
                    $q->select('user_id', 'name', 'email', 'phone', 'roles', 'qualification', 'state')
                        ->with([
                            'buyer:buyer_id,user_id,qualification,state,belongs_to_complex',
                            'domiciliary:domiciliary_id,user_id,available,document,qualification,state',
                            'rolRelation:rol_id,name'
                        ]);
                }
            ])
            ->get();

        $formatted = $affiliations->map(function ($aff) {
            return [
                'affiliation_id' => $aff->id,
                'user' => [
                    'user_id' => $aff->user->id,
                    'name' => $aff->user->name,
                    'email' => $aff->user->email,
                    'phone' => $aff->user->phone,
                    'roles' => $aff->user->rolRelation->name ?? null,
                    'qualification' => $aff->user->qualification,
                    'state' => $aff->user->state,
                    'buyer' => $aff->user->buyer ? [
                        'buyer_id' => $aff->user->buyer->buyer_id,
                        'qualification' => $aff->user->buyer->qualification,
                        'belongs_to_complex' => $aff->user->buyer->belongs_to_complex,
                        'state' => $aff->user->buyer->state,
                    ] : null,
                    'domiciliary' => $aff->user->domiciliary ? [
                        'domiciliary_id' => $aff->user->domiciliary->domiciliary_id,
                        'document' => $aff->user->domiciliary->document,
                        'available' => $aff->user->domiciliary->available,
                        'qualification' => $aff->user->domiciliary->qualification,
                        'state' => $aff->user->domiciliary->state,
                    ] : null,
                ],
            ];
        });

        return response()->json([
            'affiliations' => $formatted,
            'count' => $formatted->count()
        ]);
    }
}
