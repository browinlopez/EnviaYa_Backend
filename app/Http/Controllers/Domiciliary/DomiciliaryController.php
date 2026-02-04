<?php

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Payment\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DomiciliaryController extends Controller
{
    // Listar todos los domiciliarios
    public function listDomiciliary(Request $request)
    {
        $domiciliaries = Domiciliary::with('user', 'reviews')->get();
        return response()->json($domiciliaries);
    }

    // Listar todos los domiciliarios de un negocio
    public function listDomiciliariesByBusiness(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $business = \App\Models\Business::with(['domiciliaries.user'])->findOrFail($request->busines_id);

        $domiciliaries = $business->domiciliaries->map(function ($domiciliary) {
            return [
                'domiciliary_id' => $domiciliary->domiciliary_id,
                'name'           => $domiciliary->user ? $domiciliary->user->name : null,
                'email'          => $domiciliary->user ? $domiciliary->user->email : null,
                'phone'          => $domiciliary->user ? $domiciliary->user->phone : null,
                'available'      => $domiciliary->available,
                'qualification'  => $domiciliary->qualification,
                'state'          => $domiciliary->state,
            ];
        });

        return response()->json([
            'busines_id'    => $business->busines_id,
            'business_name' => $business->name,
            'domiciliaries' => $domiciliaries
        ]);
    }


    // Crear un domiciliario
    public function createDomiciliary(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|unique:user,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:225',
            'available' => 'boolean',
            'qualification' => 'numeric|min:0|max:5',
            'state' => 'boolean'
        ]);

        try {

            DB::beginTransaction();

            // Crear usuario
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'address' => $request->address,
                'rol' => 3,
                'qualification' => $request->qualification ?? 0,
                'state' => $request->state
            ]);

            // Crear domiciliario asociado
            $domiciliary = Domiciliary::create([
                'user_id' => $user->user_id,
                'available' => $request->available ?? false,
                'qualification' => $user->qualification,
                'state' => $user->state,
                'document' => $request->document ?? null   // si tienes este campo
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario y domiciliario creados correctamente',
                'user' => $user,
                'domiciliary' => $domiciliary
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Error al crear el domiciliario: ' . $e->getMessage()
            ], 500);
        }
    }


    // Actualizar un domiciliario
    public function updateDomiciliary(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            // Campos del domiciliario
            'available' => 'boolean',
            'qualification' => 'numeric|min:0|max:5',
            'state' => 'boolean',
            // Campos del usuario
            'name' => 'string|max:255',
            'email' => 'email|max:255',
            'phone' => 'string|max:20',
            'address' => 'string|max:255',
        ]);

        // Buscar usuario
        $user = User::findOrFail($request->user_id);

        // Actualizar usuario
        $user->update($request->only([
            'name',
            'email',
            'phone',
            'address',
        ]));

        // Obtener y actualizar domiciliario relacionado
        $domiciliary = $user->domiciliary;
        if ($domiciliary) {
            $domiciliary->update($request->only([
                'available',
                'qualification',
                'state'
            ]));
        }

        return response()->json([
            'message' => 'Información actualizada correctamente',
            'user' => $user,
            'domiciliary' => $domiciliary
        ]);
    }


    // Eliminar un domiciliario
    public function deleteDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary = Domiciliary::find($request->domiciliary_id);
        $domiciliary->delete();

        return response()->json(['message' => 'Domiciliario eliminado']);
    }

    // Obtener un domiciliario específico
    public function showDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary = Domiciliary::with('user', 'reviews')->find($request->domiciliary_id);

        return response()->json($domiciliary);
    }

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

    public function incomeDomiciliary(Request $request)
    {
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary_id = $request->domiciliary_id;

        $daysOfWeek = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo',
        ];

        // =========================
        // SEMANA ACTUAL
        // =========================
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $currentWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(domicilio) as total')
        )
            ->whereHas(
                'order',
                fn($q) =>
                $q->where('domiciliary_id', $domiciliary_id)
            )
            ->whereBetween('payment_date', [$weekStart, $weekEnd])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        // =========================
        // SEMANA ANTERIOR
        // =========================
        $prevWeekStart = now()->subWeek()->startOfWeek();
        $prevWeekEnd = now()->subWeek()->endOfWeek();

        $previousWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(domicilio) as total')
        )
            ->whereHas(
                'order',
                fn($q) =>
                $q->where('domiciliary_id', $domiciliary_id)
            )
            ->whereBetween('payment_date', [$prevWeekStart, $prevWeekEnd])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        // =========================
        // MAPEO DE DÍAS
        // =========================
        $current = [];
        $previous = [];

        foreach ($daysOfWeek as $key => $day) {
            $current[$day] = (float)($currentWeek[$key]->total ?? 0);
            $previous[$day] = (float)($previousWeek[$key]->total ?? 0);
        }

        $totalIncome = Payment::whereHas(
            'order',
            fn($q) =>
            $q->where('domiciliary_id', $domiciliary_id)
        )->sum('domicilio');

        return response()->json([
            'weekly_current' => $current,
            'weekly_previous' => $previous,
            'total_income' => (float)$totalIncome,
        ]);
    }
}
