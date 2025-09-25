<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserFavorite;
use Illuminate\Http\Request;

class BusinessUserFavoriteController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|exists:business,busines_id',
        ]);

        $userId = Auth::id();

        $favorite = BusinessUserFavorite::firstOrCreate([
            'user_id' => $userId,
            'busines_id' => $request->busines_id,
        ]);

        return response()->json(['message' => 'Afiliado correctamente', 'data' => $favorite]);
    }

    public function destroy($busines_id)
    {
        $userId = Auth::id();

        BusinessUserFavorite::where('user_id', $userId)
            ->where('busines_id', $busines_id)
            ->delete();

        return response()->json(['message' => 'Desafiliado correctamente']);
    }

    public function myAffiliations()
    {
        $userId = Auth::id();

        $favorites = BusinessUserFavorite::with('business')
            ->where('user_id', $userId)
            ->get();

        return response()->json($favorites);
    }
}
