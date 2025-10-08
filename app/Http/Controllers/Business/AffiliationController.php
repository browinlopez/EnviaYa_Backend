<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserAffiliation;
use App\Models\User;
use Illuminate\Http\Request;

class AffiliationController extends Controller
{
    // Afiliar o desafiliar
    public function toggle(Request $request)
    {
        $request->validate([
            'user_id'    => 'required|integer|exists:user,user_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $userId     = $request->user_id;
        $businessId = $request->busines_id;

        $affiliation = BusinessUserAffiliation::where('user_id', $userId)
            ->where('busines_id', $businessId)
            ->first();

        if ($affiliation) {
            $affiliation->delete();
            return response()->json(['message' => 'Usuario desafiliado']);
        } else {
            BusinessUserAffiliation::create([
                'user_id'    => $userId,
                'busines_id' => $businessId,
            ]);
            return response()->json(['message' => 'Usuario afiliado']);
        }
    }

    // Listar usuarios afiliados a una tienda
    public function listUsers(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $businessId = $request->busines_id;

        // 🔹 Cargar afiliaciones con información del usuario + relaciones
        $affiliations = BusinessUserAffiliation::where('busines_id', $businessId)
            ->with([
                'business:busines_id,name',
                'user' => function ($query) {
                    $query->select('user_id', 'name', 'email', 'phone', 'rol', 'qualification', 'state')
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
                    'id' => $aff->business->busines_id ?? null,
                    'name' => $aff->business->name ?? null,
                ],
                'user' => [
                    'id' => $aff->user->user_id ?? null,
                    'name' => $aff->user->name ?? null,
                    'email' => $aff->user->email ?? null,
                    'phone' => $aff->user->phone ?? null,
                    'rol' => $aff->user->rolRelation->name ?? null,
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


    // 🔍 Buscar comprador por número de teléfono
    public function searchBuyerByPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:7',
        ]);

        $phone = $request->phone;

        $user = User::with(['buyer'])
            ->where('phone', $phone)
            ->whereHas('buyer') // Solo los que son compradores
            ->first();

        if (!$user) {
            return response()->json(['message' => 'No se encontró comprador con ese número'], 404);
        }

        return response()->json([
            'user'  => $user,
            'buyer' => $user->buyer,
        ]);
    }
}
