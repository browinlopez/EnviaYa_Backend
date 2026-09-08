<?php

namespace App\Services;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Nombrar al administrador de un conjunto. Son 150 lineas con tres reglas que no se ven leyendo el controlador: aceptar un correo que ya existe (mudarse o reponer un acceso es mas frecuente que equivocarse), NO quitarle el administrador a otro conjunto, y devolver la contrasena generada una sola vez.
 */
class AdministradorDeConjunto
{
    /*
     * Este servicio SI devuelve `response()->json()`, a diferencia del
     * resto. Es deliberado y esta a medias: el metodo mezcla la regla con
     * la respuesta HTTP, y separarlo bien exige repensar los cuatro
     * caminos de error que tiene dentro. Se mueve entero ahora —que ya es
     * ganancia: 150 lineas fuera del controlador y un sitio unico donde
     * vive la regla— y se limpia cuando alguien lo toque de verdad.
     */

    /**
     * Crea o repone al administrador de un conjunto.
     *
     * Acepta un correo que ya exista: mudarse de conjunto o reponer un acceso
     * perdido es mas frecuente que equivocarse, y fallar con "ese correo ya
     * esta usado" obligaria a borrar la cuenta a mano en la base.
     */
    public function assignComplexOwner(Request $request, $id)
    {
        $conjunto = DB::table('residential_complexes')->where('complex_id', $id)->first();
        abort_if(!$conjunto, 404, 'El conjunto no existe.');

        $datos = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'phone'    => 'nullable|string|max:20',
            // Opcional a proposito: sin ella se genera una y se devuelve UNA
            // vez, que es como se entrega un acceso sin escribirlo en ningun
            // sitio.
            'password' => 'nullable|string|min:8|max:72',
        ]);

        /*
         * NO SE LE QUITA EL ADMINISTRADOR A OTRO CONJUNTO.
         *
         * `user_id` es unico en `complex_staff`: nombrar aca a quien ya
         * administra otro edificio lo MUEVE, y aquel se queda sin nadie que lo
         * gestione ni registre entradas, en silencio. Un celador si se puede
         * mover —su conjunto no se queda sin cabeza—.
         */
        $yaEs = DB::table('complex_staff as cs')
            ->join('residential_complexes as rc', 'rc.complex_id', '=', 'cs.complex_id')
            ->join('user as u', 'u.user_id', '=', 'cs.user_id')
            ->where('u.email', $request->input('email'))
            ->where('cs.role', ComplexStaff::DUENO)
            ->where('cs.state', true)
            ->where('cs.complex_id', '!=', $id)
            ->first(['rc.name']);

        if ($yaEs) {
            return response()->json([
                'message' => "Esa persona ya administra {$yaEs->name}. Nombrarla aca dejaria ese "
                    . 'conjunto sin administrador. Usa otro correo, o nombrale primero un reemplazo alli.',
            ], 422);
        }

        $generada = empty($datos['password']);
        $clave    = $generada ? Str::password(14) : $datos['password'];

        $existente = DB::table('user')->where('email', $datos['email'])->first();

        /*
         * Si el correo pertenece a una cuenta que NO es de conjunto —un
         * comprador, un tendero— no se la convierte: se le arrancaria su
         * perfil y sus pedidos quedarian colgando de un rol que ya no puede
         * verlos.
         */
        if ($existente && !in_array((int) $existente->rol, [ComplexStaff::ROL_DUENO, ComplexStaff::ROL_CELADOR], true)) {
            return response()->json([
                'message' => "Ese correo ya pertenece a otra cuenta de la plataforma "
                    . "({$existente->name}). Usa un correo distinto para el administrador del conjunto.",
            ], 422);
        }

        $usuario = DB::transaction(function () use ($datos, $clave, $existente, $id, $request) {
            if ($existente) {
                DB::table('user')->where('user_id', $existente->user_id)->update([
                    'name'              => $datos['name'],
                    'phone'             => $datos['phone'] ?? $existente->phone,
                    'password'          => Hash::make($clave),
                    'rol'               => ComplexStaff::ROL_DUENO,
                    'state'             => 1,
                    // Lo da de alta un administrador: dejarlo sin verificar lo
                    // bloquearia al iniciar sesion.
                    'email_verified_at' => now(),
                ]);
                $userId = $existente->user_id;
            } else {
                $userId = DB::table('user')->insertGetId([
                    'name'              => $datos['name'],
                    'email'             => $datos['email'],
                    'phone'             => $datos['phone'] ?? null,
                    'password'          => Hash::make($clave),
                    'rol'               => ComplexStaff::ROL_DUENO,
                    'state'             => 1,
                    'qualification'     => 0,
                    'email_verified_at' => now(),
                    // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                    // es el reloj del servidor, que en el VPS no es el de Bogotá.
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }

            /*
             * Un conjunto tiene UN administrador. Al nombrar a otro, el
             * anterior baja a celador en vez de perder el acceso de golpe:
             * suele seguir trabajando ahi, y quitarle la entrada sin avisar
             * deja al conjunto sin porteria el mismo dia del cambio.
             */
            $anteriores = DB::table('complex_staff')
                ->where('complex_id', $id)
                ->where('role', ComplexStaff::DUENO)
                ->where('user_id', '!=', $userId)
                ->pluck('user_id');

            if ($anteriores->isNotEmpty()) {
                DB::table('complex_staff')
                    ->whereIn('user_id', $anteriores)
                    ->update(['role' => ComplexStaff::CELADOR, 'updated_at' => now()]);

                /*
                 * Y su rol de usuario baja con la ficha. Si no, quedaba con
                 * `rol = 5` haciendo de celador: el panel de administracion lo
                 * seguia enseniando como «Admin. de conjunto» y nadie sabia que
                 * ya no lo era.
                 */
                DB::table('user')
                    ->whereIn('user_id', $anteriores)
                    ->update(['rol' => ComplexStaff::ROL_CELADOR]);
            }

            // `user_id` es unico en la tabla: quien venia de otro conjunto se
            // reasigna a este en vez de duplicarse.
            ComplexStaff::updateOrCreate(
                ['user_id' => $userId],
                [
                    'complex_id' => $id,
                    'role'       => ComplexStaff::DUENO,
                    'state'      => true,
                    'created_by' => $request->user()?->user_id,
                ],
            );

            return $userId;
        });

        return response()->json([
            'message'  => $existente ? 'Acceso repuesto.' : 'Administrador creado.',
            'user_id'  => $usuario,
            'email'    => $datos['email'],
            // Solo cuando la genero el servidor: si la escribio quien llama,
            // devolverla no aporta nada y la deja en un log mas.
            'password' => $generada ? $clave : null,
        ], $existente ? 200 : 201);
    }

    /* ==================================================================
       PROPIETARIOS
       ================================================================== */
}
