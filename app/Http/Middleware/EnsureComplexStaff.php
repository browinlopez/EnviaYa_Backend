<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conjunto\ComplexStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta del panel de aliados: dueños de conjunto y celadores.
 *
 * Paralelo a `EnsureAdmin`, no una modificación suya. Aquel tiene el rol 4
 * quemado como constante y protege el panel interno; relajarlo para que dejara
 * pasar roles nuevos ampliaría en silencio quién llega a la administración de
 * la plataforma. Dos puertas distintas para dos edificios distintos.
 *
 * Lo importante de este middleware no es que deje pasar, sino lo que DEJA
 * PUESTO: el conjunto de quien pide, en el atributo `complex_id` de la
 * petición. A partir de ahí ningún controlador tiene que acordarse de acotar
 * por conjunto leyendo un parámetro — que es justo como se filtran los datos
 * de otro sin querer.
 *
 * Uso: `->middleware('conjunto')` o `->middleware('conjunto:dueno')` cuando la
 * acción sea solo del dueño (crear celadores, por ejemplo).
 */
class EnsureComplexStaff
{
    public function handle(Request $request, Closure $next, ?string $rol = null): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        /*
         * EL ROL Y LA FICHA, LOS DOS.
         *
         * Antes se miraba solo la ficha, y eso dejaba una puerta abierta que se
         * comprobó: al bajarle el rol a un dueño de conjunto desde el panel, su
         * fila en `complex_staff` seguía ahí y `GET /v1/conjunto/residentes` le
         * seguía respondiendo 200. Se le quitaba el rol y no se le quitaba el
         * acceso.
         *
         * El origen se corrigió —cambiar el rol ahora cierra la ficha— pero la
         * defensa va acá: una fila que se quedó atrás por cualquier motivo, hoy
         * o dentro de dos años, no puede volver a valer como llave.
         */
        if (! in_array((int) $usuario->rol, ComplexStaff::ROLES_DE_USUARIO, true)) {
            return response()->json([
                'message' => 'Esta sección es para el personal de un conjunto.',
            ], 403);
        }

        /*
         * LA CUENTA DESHABILITADA, EN CADA PETICIÓN.
         *
         * `user.state` solo se miraba al iniciar sesión, y los tokens de
         * Sanctum no caducan solos: a quien se deshabilitaba desde el panel
         * le seguía valiendo el token que ya tenía.
         */
        if (! $usuario->state) {
            return response()->json([
                'message' => 'Tu cuenta está deshabilitada. Comunícate con el administrador.',
                'reason' => 'account_disabled',
            ], 403);
        }

        $ficha = ComplexStaff::de((int) $usuario->user_id);

        if (! $ficha) {
            return response()->json([
                'message' => 'Esta sección es para el personal de un conjunto.',
            ], 403);
        }

        /*
         * EL CONJUNTO TAMBIÉN TIENE QUE ESTAR ACTIVO.
         *
         * Desactivarlo desde el panel interno no toca `complex_staff`, así que
         * mirando solo la ficha su personal seguía registrando entradas y
         * bajando reportes de un edificio que la plataforma ya dio de baja.
         */
        $activo = DB::table('residential_complexes')
            ->where('complex_id', $ficha->complex_id)
            ->where('state', 1)
            ->exists();

        if (! $activo) {
            return response()->json([
                'message' => 'Este conjunto está desactivado en la plataforma. Comunícate con el equipo de VeciPa\'Ya.',
                'reason' => 'complex_disabled',
            ], 403);
        }

        if ($rol === ComplexStaff::DUENO && ! $ficha->esDueno()) {
            return response()->json([
                'message' => 'Solo el administrador del conjunto puede hacer esto.',
            ], 403);
        }

        /*
         * El conjunto viaja en la petición, resuelto desde la sesión.
         *
         * Es la línea que sostiene todo el alcance por registro: los
         * controladores leen `$request->attributes->get('complex_id')` y no
         * pueden equivocarse tomándolo de la URL.
         */
        $request->attributes->set('complex_id', (int) $ficha->complex_id);
        $request->attributes->set('complex_role', $ficha->role);

        App::setLocale('es');

        return $next($request);
    }
}
