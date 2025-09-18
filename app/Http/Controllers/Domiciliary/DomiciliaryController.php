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

        // Crear el usuario con rol 3 (Domiciliario)
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

        // Crear el domiciliario vinculado al usuario
        $domiciliary = Domiciliary::create([
            'user_id' => $user->user_id,
            'available' => $request->available ?? false,
            'qualification' => $user->qualification,
            'state' => $user->state
        ]);

        return response()->json([
            'message' => 'Usuario y domiciliario creados correctamente',
            'user' => $user,
            'domiciliary' => $domiciliary
        ]);
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
        // Solo validamos el ID del domiciliario
        $request->validate([
            'domiciliary_id' => 'required|integer|exists:domiciliary,domiciliary_id',
        ]);

        $domiciliary_id = $request->domiciliary_id;

        // Fechas actuales (semana y mes)
        $week_start = now()->startOfWeek();
        $week_end   = (clone $week_start)->endOfWeek();

        $month_start = now()->startOfMonth();
        $month_end   = (clone $month_start)->endOfMonth();

        /**
         * Ganancia semanal (sumar campo "domicilio")
         */
        $weekIncome = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(domicilio) as total_income')
        )
            ->whereHas('order', function ($q) use ($domiciliary_id) {
                $q->where('domiciliary_id', $domiciliary_id);
            })
            ->whereBetween('payment_date', [$week_start->toDateString(), $week_end->toDateString()])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        // Mapear días de la semana
        $daysOfWeek = [
            2 => 'Lunes',
            3 => 'Martes',
            4 => 'Miércoles',
            5 => 'Jueves',
            6 => 'Viernes',
            7 => 'Sábado',
            1 => 'Domingo',
        ];

        $weeklyIncome = [];
        foreach ($daysOfWeek as $key => $day) {
            $weeklyIncome[$day] = (float)($weekIncome[$key]->total_income ?? 0);
        }

        /**
         * Ganancia mensual (sumar campo "domicilio" de payments del mes)
         */
        $monthIncome = Payment::whereHas('order', function ($q) use ($domiciliary_id) {
            $q->where('domiciliary_id', $domiciliary_id);
        })
            ->whereBetween('payment_date', [$month_start->toDateString(), $month_end->toDateString()])
            ->sum('domicilio');

        /**
         * Total histórico de ingresos del domiciliario
         */
        $totalIncome = Payment::whereHas('order', function ($q) use ($domiciliary_id) {
            $q->where('domiciliary_id', $domiciliary_id);
        })
            ->sum('domicilio');

        return response()->json([
            'domiciliary_id' => $domiciliary_id,
            'week_start'     => $week_start->toDateString(),
            'week_end'       => $week_end->toDateString(),
            'weekly_income'  => $weeklyIncome,
            'month_start'    => $month_start->toDateString(),
            'month_end'      => $month_end->toDateString(),
            'monthly_income' => (float)$monthIncome,
            'total_income'   => (float)$totalIncome
        ]);
    }
}
