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
         * ENTREGAS POR DÍA, Y YA NO PESOS.
         *
         * Esta respuesta era cien por cien ganancia: todos sus campos eran
         * `SUM(domiciliary_fee)`. Se cambia a contar entregas porque el
         * domiciliario deja de ver lo que gana —ver sus ganancias mientras
         * maneja dinero ajeno es de donde salen los errores de pago— pero la
         * pantalla sí tiene que seguir dándole la medida de su semana: cuánto
         * ha repartido.
         *
         * La cifra se quita del SERVIDOR y no solo de la pantalla: un campo
         * que la API sigue devolviendo no está oculto, está a un vistazo de
         * distancia. La comisión sigue calculándose y guardándose en el
         * pedido; la tienda y la liquidación la tienen entera.
         *
         * Se agrupa por día en PHP y no con DAYOFWEEK: así la consulta es la
         * misma en MySQL y en la base de pruebas.
         */
        $porDia = function (Carbon $desde, Carbon $hasta) use ($domiciliary_id) {
            $filas = DB::table('orderssales')
                ->where('domiciliary_id', $domiciliary_id)
                ->where('state', 4)
                ->whereBetween('delivery_date', [$desde, $hasta])
                ->get(['delivery_date']);

            $dias = [];
            foreach ($filas as $f) {
                // DAYOFWEEK de MySQL: 1 = domingo … 7 = sábado.
                $dia = Carbon::parse($f->delivery_date)->dayOfWeek + 1;
                $dias[$dia] = ($dias[$dia] ?? 0) + 1;
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
            $currentValue = (int) ($currentWeek[$key]->total ?? 0);
            $previousValue = (int) ($previousWeek[$key]->total ?? 0);

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
        $totalEntregas = DB::table('orderssales')
            ->where('domiciliary_id', $domiciliary_id)
            ->where('state', 4)
            ->count();

        /*
         * Se conservan los nombres `weekly_*`: son los que la app ya lee, y
         * cambiarlos obligaría a publicar app y servidor a la vez —que es
         * justo lo que no se puede hacer con dos versiones en las tiendas—.
         * Lo que cambia es QUÉ cuentan.
         */
        return response()->json([
            'weekly_current' => $current,
            'weekly_previous' => $previous,
            'weekly_current_total' => $currentWeekTotal,
            'weekly_previous_total' => $previousWeekTotal,
            'weekly_growth_percent' => round($weeklyGrowthPercent, 2),
            'total_deliveries' => $totalEntregas,
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
