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

        $business = \App\Models\Business::with(['domiciliaries.user'])
            ->findOrFail($request->busines_id);

        // Pedidos que cada domiciliario lleva encima ahora mismo, para que el
        // tendero vea a quién puede despacharle antes de intentarlo.
        $business->domiciliaries->loadCount([
            'orders as active_orders' => fn($q) => $q->where('state', 3),
        ]);

        $maxSimultaneos = (int) Ajustes::valor('operacion.entregas_simultaneas');

        /*
         * Cuánto efectivo lleva encima cada uno.
         *
         * El tendero lo necesita ANTES de despachar: si el negocio puso tope y
         * el pedido es contra entrega, el servidor va a rechazar la asignación.
         * Sin este dato, el tendero lo intenta, recibe un error y no entiende
         * por qué — la lista le decía que esa persona estaba disponible.
         */
        $custodia = app(\App\Services\CustodiaDeEfectivo::class);
        $tope = $business->max_courier_cash !== null
            ? (float) $business->max_courier_cash
            : null;

        $domiciliaries = $business->domiciliaries->map(function ($domiciliary) use ($maxSimultaneos, $custodia, $tope) {
            $enCurso = (int) ($domiciliary->active_orders ?? 0);
            $disponible = (bool) $domiciliary->available;
            $efectivo = round($custodia->saldo($domiciliary->domiciliary_id), 2);

            return [
                'domiciliary_id' => $domiciliary->domiciliary_id,
                // user_id del domiciliario: requerido para despachar una
                // orden (transición 2 → 3 en orders/update)
                'user_id'        => $domiciliary->user_id,
                'name'           => $domiciliary->user ? $domiciliary->user->name : null,
                'email'          => $domiciliary->user ? $domiciliary->user->email : null,
                'phone'          => $domiciliary->user ? $domiciliary->user->phone : null,
                'available'      => $domiciliary->available,
                'qualification'  => $domiciliary->qualification,
                'state'          => $domiciliary->state,
                'active_orders'     => $enCurso,
                'max_active_orders' => $maxSimultaneos,
                // Mismas condiciones que valida orders/update al despachar.
                'can_take'          => $disponible && $enCurso < $maxSimultaneos,
                'cash_on_hand'      => $efectivo,
                'cash_limit'        => $tope,
                /*
                 * Cuánto le cabe todavía en efectivo. Se manda calculado y no
                 * se deja al cliente: la app y el panel harían la misma resta
                 * cada uno por su lado, y el día que el tope cambie de forma
                 * habría que acordarse de los dos.
                 */
                'cash_room'         => $tope === null ? null : max(0, round($tope - $efectivo, 2)),
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

            // Vincularlo al negocio de quien lo está creando. Sin esto el
            // domiciliario quedaba "suelto": no aparecía en el listado de la
            // tienda ni en el selector al despachar un pedido, y el tendero
            // tampoco podía editarlo.
            $negocio = $this->negocioDelCreador($request);

            if ($negocio) {
                $domiciliary->businesses()->syncWithoutDetaching([
                    $negocio => ['state' => 1],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Usuario y domiciliario creados correctamente',
                'user' => $user,
                'domiciliary' => $domiciliary->load('businesses'),
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
            'state' => 'boolean',
            // Campos del usuario
            'name' => 'string|max:255',
            // El correo es la credencial de acceso: no puede chocar con otro.
            'email' => 'email|max:255|unique:user,email,' . $request->user_id . ',user_id',
            'phone' => 'string|max:20',
            'address' => 'string|max:255',
        ]);

        // Buscar usuario
        $user = User::findOrFail($request->user_id);

        // Solo puede editar el propio domiciliario o el dueño de un negocio al
        // que esté asignado. Antes cualquier sesión válida podía modificar a
        // cualquier usuario mandando su user_id.
        if (!$this->puedeEditarDomiciliario($request->user(), $user)) {
            return response()->json([
                'message' => 'No tienes permiso para editar este domiciliario',
            ], 403);
        }

        // Actualizar usuario
        $user->update($request->only([
            'name',
            'email',
            'phone',
            'address',
        ]));

        // Obtener y actualizar domiciliario relacionado.
        // `qualification` se excluye a propósito: la calificación la producen
        // las reseñas de los compradores, no puede fijarse a mano desde aquí.
        $domiciliary = $user->domiciliary;
        if ($domiciliary) {
            $domiciliary->update($request->only([
                'available',
                'state'
            ]));
        }

        return response()->json([
            'message' => 'Información actualizada correctamente',
            'user' => $user,
            'domiciliary' => $domiciliary
        ]);
    }

    /**
     * Negocio al que se vincula un domiciliario recién creado: el indicado en
     * la petición si pertenece a quien crea, o su primer negocio.
     */
    private function negocioDelCreador(Request $request): ?int
    {
        $owner = $request->user()?->owner;

        if (!$owner) {
            return null;
        }

        $propios = $owner->businesses->pluck('busines_id');

        if ($request->filled('busines_id')) {
            return $propios->contains((int) $request->busines_id)
                ? (int) $request->busines_id
                : null;
        }

        return $propios->first();
    }

    /**
     * Un domiciliario puede editarse a sí mismo; un dueño de negocio puede
     * editar a los domiciliarios asignados a alguno de sus negocios.
     */
    private function puedeEditarDomiciliario($autenticado, User $objetivo): bool
    {
        if (!$autenticado) {
            return false;
        }

        if ((int) $autenticado->user_id === (int) $objetivo->user_id) {
            return true;
        }

        $domiciliary = $objetivo->domiciliary;

        if (!$domiciliary || !$autenticado->owner) {
            return false;
        }

        $negociosPropios = $autenticado->owner->businesses->pluck('busines_id');

        return $domiciliary->businesses()
            ->whereIn('business.busines_id', $negociosPropios)
            ->exists();
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
