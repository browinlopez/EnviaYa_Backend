<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\CambiarCeladorRequest;
use App\Http\Requests\Conjunto\CrearCeladorRequest;
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
                ->map(function ($c) use ($libro) {
                    $l = $libro[$c->user_id] ?? null;
                    $c->entradas = (int) ($l->entradas ?? 0);
                    $c->ultima_entrada = $l->ultima ?? null;
                    $c->must_change_password = (bool) $c->must_change_password;

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
        }

        return response()->json([
            'message' => $datos['state']
                ? 'Celador activado.'
                : 'Celador desactivado. Ya no puede entrar al panel.',
        ]);
    }
}
