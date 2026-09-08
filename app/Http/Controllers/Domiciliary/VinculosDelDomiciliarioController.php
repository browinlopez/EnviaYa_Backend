<?php

namespace App\Http\Controllers\Domiciliary;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Payment\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class VinculosDelDomiciliarioController extends Controller
{
    // Asignar un domiciliario a un negocio
    public function assignToBusiness(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
            'state' => 'boolean'
        ]);

        $domiciliary = Domiciliary::findOrFail($request->domiciliary_id);

        $domiciliary->businesses()->syncWithoutDetaching([
            $request->busines_id => ['state' => $request->state ?? true]
        ]);

        return response()->json([
            'message' => 'Domiciliario asignado al negocio correctamente',
            'domiciliary_id' => $request->domiciliary_id,
            'busines_id' => $request->busines_id
        ]);
    }

    // Listar negocios asignados a un domiciliario
    public function listBusinessesByDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary = Domiciliary::with(['user', 'businesses'])->findOrFail($request->domiciliary_id);

        $formatted = [
            'domiciliary' => [
                'domiciliary_id' => $domiciliary->domiciliary_id,
                'name'           => $domiciliary->user ? $domiciliary->user->name : null,
                'email'          => $domiciliary->user ? $domiciliary->user->email : null,
                'phone'          => $domiciliary->user ? $domiciliary->user->phone : null,
                'state'          => $domiciliary->state,
            ],
            'businesses' => $domiciliary->businesses->map(function ($business) {
                return [
                    'busines_id'      => $business->busines_id,
                    'name'            => $business->name,
                    'phone'           => $business->phone,
                    'address'         => $business->address,
                    'qualification'   => $business->qualification,
                    'razonSocial_DCD' => $business->razonSocial_DCD,
                    'NIT'             => $business->NIT,
                    'logo'            => $business->logo,
                    'municipality_id' => $business->municipality_id,
                    'state'           => $business->state,
                ];
            }),
        ];

        return response()->json($formatted);
    }
}
