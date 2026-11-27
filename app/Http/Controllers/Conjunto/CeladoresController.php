<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\CambiarCeladorRequest;
use App\Http\Requests\Conjunto\CrearCeladorRequest;
use App\Models\Conjunto\ComplexShift;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CeladoresController extends Controller
{
    /* --------------------------- CELADORES --------------------------- */

    public function celadores(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        /*
         * Cuántas entradas registró cada uno EN ESTE conjunto y cuándo fue la
         * última: es lo que dice si un celador está trabajando de verdad o
         * sólo tiene la cuenta.
         */
        $libro = DB::table('complex_entries')
            ->where('complex_id', $complexId)
            ->whereNotNull('registered_by')
            ->groupBy('registered_by')
            ->get(['registered_by', DB::raw('COUNT(*) as entradas'), DB::raw('MAX(created_at) as ultima')])
            ->keyBy('registered_by');

        /*
         * El ÚLTIMO TURNO de cada uno: cuándo EMPEZÓ, no la hora de la última
         * entrada que anotó. Un turno de noche sin movimiento después de las
         * diez decía «último turno 9:54 p. m.» aunque hubiera empezado a las
         * seis.
         */
        $ultimoTurno = DB::table('complex_shifts')
            ->where('complex_id', $complexId)
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(started_at) as inicio')
            ->pluck('inicio', 'user_id');

        // Quién está en la puerta ahora: el turno abierto de cada uno.
        $enTurno = DB::table('complex_shifts')
            ->where('complex_id', $complexId)
            ->whereNull('ended_at')
            ->pluck('started_at', 'user_id');

        return response()->json([
            'data' => ComplexStaff::where('complex_id', $complexId)
                ->where('role', ComplexStaff::CELADOR)
                ->join('user as u', 'u.user_id', '=', 'complex_staff.user_id')
                ->orderByDesc('complex_staff.state')
                ->orderBy('u.name')
                ->get([
                    'complex_staff.id', 'complex_staff.state',
                    'u.user_id', 'u.name', 'u.email', 'u.phone',
                    // Si todavía entra con la clave que le dio la administración.
                    'u.must_change_password',
                ])
                ->map(function ($c) use ($libro, $enTurno, $ultimoTurno) {
                    $l = $libro[$c->user_id] ?? null;
                    $c->entradas = (int) ($l->entradas ?? 0);
                    $c->ultima_entrada = $l->ultima ?? null;
                    $c->must_change_password = (bool) $c->must_change_password;
                    $c->en_turno_desde = isset($enTurno[$c->user_id]) ? (string) $enTurno[$c->user_id] : null;
                    $c->ultimo_turno = isset($ultimoTurno[$c->user_id]) ? (string) $ultimoTurno[$c->user_id] : null;

                    return $c;
                }),
        ]);
    }

    /**
     * El dueño da de alta a un celador de SU conjunto.
     *
     * El conjunto sale de la sesión, nunca del cuerpo: si viniera en la
     * petición, un dueño podría crearle personal al edificio del vecino.
     */
    public function crearCelador(CrearCeladorRequest $request)
    {
        $datos = $request->validated();

        $complexId = (int) $request->attributes->get('complex_id');

        if (! Rol::find(ComplexStaff::ROL_CELADOR)) {
            return response()->json([
                'message' => 'Falta el rol de celador en la base. Avísale al equipo.',
            ], 422);
        }

        $celador = DB::transaction(function () use ($datos, $complexId, $request) {
            $user = User::create([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'phone' => $datos['phone'] ?? null,
                'rol' => ComplexStaff::ROL_CELADOR,
                'state' => true,
                // Sin verificación por correo: la cuenta la crea alguien que
                // ya respondió por esa persona, y la portería no puede esperar
                // a que alguien abra un enlace.
                'email_verified_at' => now(),
            ]);

            /*
             * La clave la escribió el administrador: la conocen los dos. Hasta
             * que el celador ponga la suya no pasa de «Crea tu clave».
             * Fuera de `create` porque no es un dato que se acepte del cuerpo.
             */
            $user->forceFill(['must_change_password' => true])->save();

            ComplexStaff::create([
                'user_id' => $user->user_id,
                'complex_id' => $complexId,
                'role' => ComplexStaff::CELADOR,
                'state' => true,
                'created_by' => $request->user()->user_id,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Celador creado. Ya puede entrar con ese correo.',
            'user_id' => $celador->user_id,
        ], 201);
    }

    /**
     * LO QUE HIZO UN CELADOR, DEL MÁS RECIENTE AL MÁS VIEJO.
     *
     * Una sola línea de tiempo con cinco fuentes: las entradas que anotó, las
     * salidas que marcó, sus turnos (inicio y fin), su alta y cada vez que se
     * le activó o desactivó (de `audits`, con quién lo hizo).
     *
     * PAGINADA SIN UNIR EN SQL. Las cinco fuentes viven en tablas distintas y
     * un UNION con fechas de columnas diferentes se escribe distinto en cada
     * motor. Para servir la página `p` basta con traer de cada fuente sus
     * `p × por_página` más recientes, mezclarlas y cortar: lo que no entra en
     * ninguno de esos cortes tampoco puede estar en la página. El total es la
     * suma de los conteos. Tope de 50 páginas de fondo: es un historial para
     * mirar, no para exportar.
     */
    public function historial(Request $request, int $id)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $ficha = ComplexStaff::where('id', $id)
            ->where('complex_id', $complexId)
            ->where('role', ComplexStaff::CELADOR)
            ->first();

        if (! $ficha) {
            return response()->json(['message' => 'Ese celador no es de tu conjunto.'], 404);
        }

        $porPagina = min(50, max(5, (int) $request->query('per_page', 20)));
        $pagina = min(50, max(1, (int) $request->query('page', 1)));
        $hondo = $pagina * $porPagina;
        $userId = (int) $ficha->user_id;

        $entradas = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->where('e.registered_by', $userId);
        $salidas = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->where('e.exit_registered_by', $userId)
            ->whereNotNull('e.exited_at');
        $turnos = DB::table('complex_shifts')
            ->where('complex_id', $complexId)
            ->where('user_id', $userId);
        $accesos = DB::table('audits as a')
            ->where('a.auditable_type', $ficha->getMorphClass())
            ->where('a.auditable_id', $ficha->id)
            ->where('a.event', 'updated')
            ->where('a.new_values', 'like', '%"state"%');

        $quien = fn ($q) => $q
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id');

        $eventos = collect()
            ->concat($quien(clone $entradas)->orderByDesc('e.created_at')->limit($hondo)
                ->get(['e.id', 'e.kind', 'e.method', 'e.created_at as fecha', DB::raw('COALESCE(u.name, e.visitor_name) as nombre')])
                ->map(fn ($e) => [
                    'tipo' => 'entrada',
                    'fecha' => (string) $e->fecha,
                    'texto' => 'Registró la entrada de '.($e->nombre ?: 'alguien sin nombre'),
                    'kind' => $e->kind,
                    'method' => $e->method,
                    'entry_id' => (int) $e->id,
                ]))
            ->concat($quien(clone $salidas)->orderByDesc('e.exited_at')->limit($hondo)
                ->get(['e.id', 'e.kind', 'e.exited_at as fecha', DB::raw('COALESCE(u.name, e.visitor_name) as nombre')])
                ->map(fn ($e) => [
                    'tipo' => 'salida',
                    'fecha' => (string) $e->fecha,
                    'texto' => 'Marcó la salida de '.($e->nombre ?: 'alguien sin nombre'),
                    'kind' => $e->kind,
                    'entry_id' => (int) $e->id,
                ]))
            ->concat((clone $turnos)->orderByDesc('started_at')->limit($hondo)->get(['started_at'])
                ->map(fn ($t) => ['tipo' => 'turno_inicio', 'fecha' => (string) $t->started_at, 'texto' => 'Empezó su turno']))
            ->concat((clone $turnos)->whereNotNull('ended_at')->orderByDesc('ended_at')->limit($hondo)->get(['started_at', 'ended_at'])
                ->map(fn ($t) => [
                    'tipo' => 'turno_fin',
                    'fecha' => (string) $t->ended_at,
                    'texto' => 'Terminó su turno',
                    'minutos' => max(0, (int) floor((strtotime((string) $t->ended_at) - strtotime((string) $t->started_at)) / 60)),
                ]))
            ->concat((clone $accesos)->leftJoin('user as p', 'p.user_id', '=', 'a.user_id')
                ->orderByDesc('a.created_at')->limit($hondo)
                ->get(['a.new_values', 'a.created_at as fecha', 'p.name as por'])
                ->map(function ($a) {
                    $activo = (bool) (json_decode((string) $a->new_values, true)['state'] ?? false);

                    return [
                        'tipo' => $activo ? 'activado' : 'desactivado',
                        'fecha' => (string) $a->fecha,
                        'texto' => ($activo ? 'Lo activaron de nuevo' : 'Lo desactivaron').($a->por ? " ({$a->por})" : ''),
                    ];
                }))
            ->push([
                'tipo' => 'alta',
                'fecha' => (string) $ficha->getRawOriginal('created_at'),
                'texto' => 'Le crearon la cuenta de celador',
            ])
            ->filter(fn ($e) => $e['fecha'] !== '')
            ->sortByDesc('fecha')
            ->values();

        $total = (clone $entradas)->count() + (clone $salidas)->count()
            + (clone $turnos)->count() + (clone $turnos)->whereNotNull('ended_at')->count()
            + (clone $accesos)->count() + ($ficha->getRawOriginal('created_at') ? 1 : 0);

        return response()->json([
            'data' => $eventos->forPage($pagina, $porPagina)->values(),
            'meta' => [
                'page' => $pagina,
                'per_page' => $porPagina,
                'total' => $total,
                'last_page' => min(50, max(1, (int) ceil($total / $porPagina))),
            ],
        ]);
    }

    /** Activa o desactiva a un celador. Solo los del propio conjunto. */
    public function cambiarCelador(CambiarCeladorRequest $request, int $id)
    {
        /*
         * Activar, desactivar, o corregir nombre y teléfono. El correo no:
         * es con lo que entra, y cambiarlo desde acá lo dejaría afuera sin
         * que él lo sepa.
         */
        $datos = $request->validated();

        $complexId = (int) $request->attributes->get('complex_id');

        $ficha = ComplexStaff::where('id', $id)
            ->where('complex_id', $complexId)
            ->where('role', ComplexStaff::CELADOR)
            ->first();

        // 404 y no 403: confirmar que existe pero es de otro conjunto ya sería
        // decirle algo del edificio del vecino.
        if (! $ficha) {
            return response()->json(['message' => 'Ese celador no es de tu conjunto.'], 404);
        }

        $usuario = User::find($ficha->user_id);

        /*
         * Por el MODELO y no con `User::where()->update()`: aquello saltaba los
         * eventos y el cambio de nombre o teléfono no quedaba en la auditoría,
         * ni quién lo hizo ni el valor anterior.
         */
        if ($usuario && (array_key_exists('name', $datos) || array_key_exists('phone', $datos))) {
            $usuario->update(array_intersect_key($datos, ['name' => 1, 'phone' => 1]));
        }

        /*
         * RESTABLECER LA CLAVE. La nueva la conoce el administrador, así que
         * vuelve a ser provisional, y se cierran sus sesiones: quien tuviera
         * la anterior no sigue adentro.
         */
        if ($usuario && array_key_exists('password', $datos)) {
            $usuario->forceFill([
                'password' => Hash::make($datos['password']),
                'must_change_password' => true,
            ])->save();
            $usuario->tokens()->delete();
        }

        if (! array_key_exists('state', $datos)) {
            return response()->json([
                'message' => array_key_exists('password', $datos)
                    ? 'Clave restablecida. Al entrar tendrá que crear la suya.'
                    : 'Datos del celador guardados.',
            ]);
        }

        $ficha->state = $datos['state'];
        $ficha->save();

        /*
         * Desactivar cierra TODAS sus sesiones.
         *
         * La ficha apagada ya lo sacaba de este panel, pero el token seguía
         * vivo para el resto del API autenticado. Si se le vuelve a activar,
         * inicia sesión de nuevo.
         */
        if (! $datos['state'] && $usuario) {
            $usuario->tokens()->delete();
            // Y su turno, si lo tenía abierto: quien ya no puede entrar no
            // sigue «en turno» en la tarjeta.
            ComplexShift::cerrarDe((int) $usuario->user_id);
        }

        return response()->json([
            'message' => $datos['state']
                ? 'Celador activado.'
                : 'Celador desactivado. Ya no puede entrar al panel.',
        ]);
    }
}
