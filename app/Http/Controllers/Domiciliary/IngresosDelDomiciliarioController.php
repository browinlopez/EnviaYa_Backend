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

class IngresosDelDomiciliarioController extends Controller
{
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

        /* =========================
       SEMANA ACTUAL
    ========================== */
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $currentWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(domiciliary_fee) as total')
        )
            ->whereHas(
                'order',
                fn($q) =>
                $q->where('domiciliary_id', $domiciliary_id)
            )
            // Solo pagos aprobados: Bold registra una fila por cada intento
            // de cobro, y contarlos todos multiplicaría el domicilio de una
            // misma entrega por cada reintento del cliente.
            ->where('payment_status', 1)
            ->whereBetween('payment_date', [$weekStart, $weekEnd])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        /* =========================
       SEMANA ANTERIOR
    ========================== */
        $prevWeekStart = now()->subWeek()->startOfWeek();
        $prevWeekEnd = now()->subWeek()->endOfWeek();

        $previousWeek = Payment::select(
            DB::raw('DAYOFWEEK(payment_date) as weekday'),
            DB::raw('SUM(domiciliary_fee) as total')
        )
            ->whereHas(
                'order',
                fn($q) =>
                $q->where('domiciliary_id', $domiciliary_id)
            )
            // Solo pagos aprobados: Bold registra una fila por cada intento
            // de cobro, y contarlos todos multiplicaría el domicilio de una
            // misma entrega por cada reintento del cliente.
            ->where('payment_status', 1)
            ->whereBetween('payment_date', [$prevWeekStart, $prevWeekEnd])
            ->groupBy('weekday')
            ->get()
            ->keyBy('weekday');

        /* =========================
       MAPEO DE DÍAS
    ========================== */
        $current = [];
        $previous = [];
        $currentWeekTotal = 0;
        $previousWeekTotal = 0;

        foreach ($daysOfWeek as $key => $day) {
            $currentValue = (float) ($currentWeek[$key]->total ?? 0);
            $previousValue = (float) ($previousWeek[$key]->total ?? 0);

            $current[$day] = $currentValue;
            $previous[$day] = $previousValue;

            $currentWeekTotal += $currentValue;
            $previousWeekTotal += $previousValue;
        }

        /* =========================
       CRECIMIENTO %
    ========================== */
        if ($previousWeekTotal > 0) {
            $weeklyGrowthPercent =
                (($currentWeekTotal - $previousWeekTotal) / $previousWeekTotal) * 100;
        } else {
            $weeklyGrowthPercent = $currentWeekTotal > 0 ? 100 : 0;
        }

        /* =========================
       TOTAL HISTÓRICO
    ========================== */
        $totalIncome = Payment::whereHas(
            'order',
            fn($q) =>
            $q->where('domiciliary_id', $domiciliary_id)
        )
            ->where('payment_status', 1)
            ->sum('domiciliary_fee');

        return response()->json([
            'weekly_current' => $current,
            'weekly_previous' => $previous,
            'weekly_current_total' => $currentWeekTotal,
            'weekly_previous_total' => $previousWeekTotal,
            'weekly_growth_percent' => round($weeklyGrowthPercent, 2),
            'total_income' => (float) $totalIncome,
        ]);
    }

    /**
     * SU CÓDIGO DE ENTRADA A LOS CONJUNTOS.
     *
     * La app lo pinta como QR y el celador lo escanea en la portería. Caduca
     * en cinco minutos: es lo que se tarda en llegar de la moto a la puerta, y
     * un código que no caduca deja de probar que quien está ahí es él.
     *
     * El domiciliario sale de la SESIÓN. Con un identificador por parámetro,
     * cualquiera podría pedir el código de otro y entrar en su nombre.
     */
    public function codigoDeAcceso(Request $request)
    {
        $domiciliario = Domiciliary::where('user_id', $request->user()->user_id)->first();

        if (!$domiciliario) {
            return response()->json(['message' => 'Esta cuenta no es de un domiciliario.'], 403);
        }

        return response()->json(
            app(\App\Services\AccesoAlConjunto::class)->generar($domiciliario)
        );
    }
}
