<?php

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Lo que el panel de aliados necesita saber al entrar, y la gestión del
 * personal del conjunto.
 *
 * `mio()` devuelve la misma FORMA que `/admin/me/permissions` —permisos por
 * clave con `view` y `manage`— a propósito: así el panel de aliados puede
 * reutilizar tal cual el contexto de sesión, el menú por permisos y el guardia
 * de rutas del panel interno, que ya están probados. Lo que cambia es de dónde
 * salen los permisos: aquí no hay áreas, hay dos roles fijos.
 */
class MiConjuntoController extends Controller
{
    /** Qué puede hacer cada rol. Dos roles, sin matriz configurable. */
    private const PERMISOS = [
        ComplexStaff::DUENO => [
            'resumen'   => ['view' => true, 'manage' => false],
            'porteria'  => ['view' => true, 'manage' => true],
            'entradas'  => ['view' => true, 'manage' => false],
            'residentes' => ['view' => true, 'manage' => false],
            'celadores' => ['view' => true, 'manage' => true],
            // El histórico del edificio es suyo: cuánto pide cada torre, qué
            // domiciliarios entran, cómo se identifican.
            'reportes'  => ['view' => true, 'manage' => false],
        ],
        ComplexStaff::CELADOR => [
            /*
             * El celador registra entradas; no ve quién vive dónde, ni
             * administra cuentas, ni tiene el histórico del edificio. Su
             * trabajo es la puerta.
             *
             * El resumen sí: necesita saber cuánto movimiento lleva el turno y
             * a qué horas suele apretarse. Es lo mismo que tiene delante, en
             * cifras.
             */
            'resumen'   => ['view' => true, 'manage' => false],
            'porteria'  => ['view' => true, 'manage' => true],
            'entradas'  => ['view' => true, 'manage' => false],
        ],
    ];

    public function mio(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');
        $rol       = (string) $request->attributes->get('complex_role');

        $conjunto = ResidentialComplex::find($complexId);

        return response()->json([
            'complex' => $conjunto ? [
                'complex_id'           => $conjunto->complex_id,
                'name'                 => $conjunto->name,
                'address'              => $conjunto->address,
                'towers_count'         => $conjunto->towers_count,
                'apartments_per_tower' => $conjunto->apartments_per_tower,
            ] : null,
            'role'        => $rol,
            'permissions' => self::PERMISOS[$rol] ?? [],
            'stats'       => [
                'residentes' => DB::table('buyer_complex')
                    ->where('complex_id', $complexId)->count(),
                'entradas_hoy' => DB::table('complex_entries')
                    ->where('complex_id', $complexId)
                    ->whereDate('created_at', now()->toDateString())
                    ->count(),
                'celadores' => ComplexStaff::where('complex_id', $complexId)
                    ->where('role', ComplexStaff::CELADOR)
                    ->where('state', true)
                    ->count(),
            ],
        ]);
    }

    /* --------------------------- CELADORES --------------------------- */

    public function celadores(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        return response()->json([
            'data' => ComplexStaff::where('complex_id', $complexId)
                ->where('role', ComplexStaff::CELADOR)
                ->join('user as u', 'u.user_id', '=', 'complex_staff.user_id')
                ->orderBy('u.name')
                ->get([
                    'complex_staff.id', 'complex_staff.state',
                    'u.user_id', 'u.name', 'u.email', 'u.phone',
                ]),
        ]);
    }

    /**
     * El dueño da de alta a un celador de SU conjunto.
     *
     * El conjunto sale de la sesión, nunca del cuerpo: si viniera en la
     * petición, un dueño podría crearle personal al edificio del vecino.
     */
    public function crearCelador(Request $request)
    {
        $datos = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:user,email',
            'password' => ['required', Password::min(8)],
            'phone'    => 'nullable|string|max:20',
        ]);

        $complexId = (int) $request->attributes->get('complex_id');

        if (!Rol::find(ComplexStaff::ROL_CELADOR)) {
            return response()->json([
                'message' => 'Falta el rol de celador en la base. Avísale al equipo.',
            ], 422);
        }

        $celador = DB::transaction(function () use ($datos, $complexId, $request) {
            $user = User::create([
                'name'     => $datos['name'],
                'email'    => $datos['email'],
                'password' => Hash::make($datos['password']),
                'phone'    => $datos['phone'] ?? null,
                'rol'      => ComplexStaff::ROL_CELADOR,
                'state'    => true,
                // Sin verificación por correo: la cuenta la crea alguien que
                // ya respondió por esa persona, y la portería no puede esperar
                // a que alguien abra un enlace.
                'email_verified_at' => now(),
            ]);

            ComplexStaff::create([
                'user_id'    => $user->user_id,
                'complex_id' => $complexId,
                'role'       => ComplexStaff::CELADOR,
                'state'      => true,
                'created_by' => $request->user()->user_id,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Celador creado. Ya puede entrar con ese correo.',
            'user_id' => $celador->user_id,
        ], 201);
    }

    /** Activa o desactiva a un celador. Solo los del propio conjunto. */
    public function cambiarCelador(Request $request, int $id)
    {
        $datos = $request->validate(['state' => 'required|boolean']);

        $complexId = (int) $request->attributes->get('complex_id');

        $ficha = ComplexStaff::where('id', $id)
            ->where('complex_id', $complexId)
            ->where('role', ComplexStaff::CELADOR)
            ->first();

        // 404 y no 403: confirmar que existe pero es de otro conjunto ya sería
        // decirle algo del edificio del vecino.
        if (!$ficha) {
            return response()->json(['message' => 'Ese celador no es de tu conjunto.'], 404);
        }

        $ficha->state = $datos['state'];
        $ficha->save();

        return response()->json([
            'message' => $datos['state']
                ? 'Celador activado.'
                : 'Celador desactivado. Ya no puede entrar al panel.',
        ]);
    }

    /* --------------------------- RESIDENTES --------------------------- */

    /**
     * Quién vive en el conjunto, según lo que declararon al registrarse.
     *
     * Sin correo ni teléfono a propósito. El dueño necesita saber cuánta gente
     * de su edificio usa la plataforma y en qué torres, no una lista de
     * contactos de sus residentes: eso son datos personales de terceros y su
     * relación es con la plataforma, no con la administración.
     */
    public function residentes(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $filas = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            })
            ->groupBy('ua.tower')
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->get([
                'ua.tower',
                DB::raw('COUNT(DISTINCT b.buyer_id) as residentes'),
            ]);

        return response()->json([
            'data'  => $filas,
            'total' => (int) $filas->sum('residentes'),
        ]);
    }
}
