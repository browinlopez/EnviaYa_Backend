<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Volver a entrar con la huella, sin escribir la contraseña.
 *
 * QUÉ SE GUARDA EN EL TELÉFONO, Y QUÉ NO.
 *
 * No se guarda la contraseña. Nunca. Lo que queda en el llavero del
 * dispositivo —Keychain en iOS, Keystore en Android, detrás de la huella— es
 * un token de Sanctum con UNA sola habilidad: `biometrico`. Con él no se
 * pueden ver pedidos, ni pagar, ni cambiar nada. Solo sirve para pedir una
 * sesión nueva en `entrar`, y para eso hace falta además haber pasado la
 * huella en el teléfono.
 *
 * Si alguien roba ese token de un dispositivo comprometido, lo único que puede
 * hacer es cambiarlo por una sesión; grave, pero mucho menos que una
 * contraseña reutilizada en otros servicios. Y se revoca desde la pantalla de
 * seguridad sin tocar la contraseña.
 *
 * POR QUÉ SOBREVIVE AL CIERRE DE SESIÓN. Es justo lo que se pidió: cerrar
 * sesión y poder volver con la huella. `logout` borra las sesiones y deja este
 * token en pie; `olvidar` es el que lo mata, y es lo que hay que llamar cuando
 * alguien quiere sacar de verdad su cuenta del teléfono.
 */
class AccesoBiometricoController extends Controller
{
    /** La habilidad que marca al token guardado tras la huella. */
    public const HABILIDAD = 'biometrico';

    /** Nombre del token. `AuthController::logout` lo usa para NO borrarlo. */
    public const NOMBRE_TOKEN = 'biometrico';

    /**
     * Activar: entrega el token que el teléfono guardará tras la huella.
     *
     * Se llama con una sesión normal ya abierta, o sea después de escribir la
     * contraseña. Nunca es la primera puerta.
     */
    public function habilitar(Request $request)
    {
        $user = $request->user();

        // Uno por cuenta: activar de nuevo invalida el anterior. Si alguien
        // cambió de teléfono, el viejo deja de servir sin tener que acordarse.
        $this->borrarLosBiometricos($user);

        $token = $user->createToken(self::NOMBRE_TOKEN, [self::HABILIDAD])->plainTextToken;

        $user->tokens()->latest('id')->limit(1)->update([
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'message' => 'Acceso con huella activado.',
            'token'   => $token,
            // Lo justo para que la pantalla de inicio pueda decir "Entrar como
            // Browin" sin haber abierto sesión todavía. Ni correo ni teléfono:
            // un nombre en una pantalla bloqueada es bastante menos que eso.
            'perfil'  => [
                'user_id' => $user->user_id,
                'nombre'  => $user->name,
                'rol'     => $user->rol,
            ],
        ]);
    }

    /**
     * Entrar: cambia el token de la huella por una sesión de verdad.
     *
     * La ruta está detrás de `ability:biometrico`, así que un token de sesión
     * normal NO puede llamarla y este token no puede hacer nada más.
     */
    public function entrar(Request $request)
    {
        $user = $request->user();

        if (!$user->state) {
            return response()->json([
                'message' => 'Tu cuenta está deshabilitada. Comunícate con el administrador.',
                'reason'  => 'account_disabled',
            ], 403);
        }

        /*
         * El correo verificado se comprueba igual que en el login normal.
         *
         * Alguien pudo activar la huella y quedar sin verificar después —un
         * cambio de correo, por ejemplo—. Saltarse esa puerta acá sería dejar
         * una entrada lateral a una comprobación que existe por algo.
         */
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Debes verificar tu correo antes de iniciar sesión',
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->tokens()->latest('id')->limit(1)->update([
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        // Las mismas relaciones que carga el login: la app espera el usuario
        // completo para saber a qué pantalla llevarlo.
        if ($user->rol == 1) {
            $user->load('buyer');
        } elseif ($user->rol == 2) {
            $user->load(['owner.businesses']);
        } elseif ($user->rol == 3) {
            $user->load(['domiciliary.businesses']);
        }

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    /**
     * Olvidar este teléfono.
     *
     * Lo llama quien apaga la opción, y también la app cuando alguien elige
     * "cerrar sesión y olvidar la cuenta". Después de esto hay que volver a
     * escribir la contraseña.
     */
    public function olvidar(Request $request)
    {
        $this->borrarLosBiometricos($request->user());

        return response()->json(['message' => 'Acceso con huella desactivado.']);
    }

    private function borrarLosBiometricos(User $user): void
    {
        $user->tokens()->where('name', self::NOMBRE_TOKEN)->delete();
    }
}
