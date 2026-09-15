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

        /*
         * LO GANADO SALE DE LOS PEDIDOS ENTREGADOS, NO DE LA CAJA.
         *
         * Se sumaba `payments.domiciliary_fee`, y `payments` es la caja de la
         * plataforma: un pedido pagado con el crédito de la tienda no deja fila
         * ahí —esa plata nunca entra— y el domiciliario lo entregaba sin que
         * contara en su ganancia. Lo que gana es su parte de cada pedido que
         * ENTREGÓ, venga de donde venga el pago.
         *
         * Se agrupa por día en PHP y no con DAYOFWEEK: así la consulta es la
         * misma en MySQL y en la base de pruebas.
         */
        $porDia = function (Carbon $desde, Carbon $hasta) use ($domiciliary_id) {
            $filas = DB::table('orderssales')
                ->where('domiciliary_id', $domiciliary_id)
                ->where('state', 4)
                ->whereBetween('delivery_date', [$desde, $hasta])
                ->get(['delivery_date', 'domiciliary_fee']);

            $dias = [];
            foreach ($filas as $f) {
                // DAYOFWEEK de MySQL: 1 = domingo … 7 = sábado.
                $dia = Carbon::parse($f->delivery_date)->dayOfWeek + 1;
                $dias[$dia] = ($dias[$dia] ?? 0) + (float) $f->domiciliary_fee;
            }

            return collect($dias)->map(fn ($total) => (object) ['total' => $total]);
        };

        $currentWeek  = $porDia(now()->startOfWeek(), now()->endOfWeek());
        $previousWeek = $porDia(now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek());

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
        $totalIncome = DB::table('orderssales')
            ->where('domiciliary_id', $domiciliary_id)
            ->where('state', 4)
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
