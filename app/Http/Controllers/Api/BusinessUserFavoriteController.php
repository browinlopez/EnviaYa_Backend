<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessUserFavorite;
use Illuminate\Http\Request;

class BusinessUserFavoriteController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'business_id' => 'required|exists:business,business_id',
        ]);

        $userId = Auth::id();

        $favorite = BusinessUserFavorite::firstOrCreate([
            'user_id' => $userId,
            'business_id' => $request->business_id,
        ]);

        return response()->json(['message' => 'Afiliado correctamente', 'data' => $favorite]);
    }

    public function destroy($business_id)
    {
        $userId = Auth::id();

        BusinessUserFavorite::where('user_id', $userId)
            ->where('business_id', $business_id)
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
