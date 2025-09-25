<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserAffiliation;
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

        $users = BusinessUserAffiliation::where('busines_id', $businessId)
            ->with('business')
            ->get();

        return response()->json(['affiliations' => $users]);
    }
}
