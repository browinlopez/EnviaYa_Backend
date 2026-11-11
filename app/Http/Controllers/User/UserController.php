<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ActualizarPerfilRequest;
use App\Http\Requests\User\CambiarContrasenaRequest;
use App\Http\Requests\User\DesactivarCuentaRequest;
use App\Models\Buyer\Buyer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ComprobarPertenencia;

    // Listar todos los usuarios con relaciones
    public function index()
    {
        $users = User::with([
            'buyer.complexes.residentialComplex',
            'addresses',
        ])
            ->where('rol', 4)
            ->get();

        $formatted = $users->map(function ($user) {
            return [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'rol' => $user->rol,
                'qualification' => $user->buyer ? $user->buyer->qualification : null,
                'state' => $user->state,

                // Buyer directo (sin arreglo)
                'buyer_id' => $user->buyer ? $user->buyer->buyer_id : null,

                // Complexes simplificado (id y nombre del residencial)
                'complexes' => $user->buyer && $user->buyer->complexes ? $user->buyer->complexes->map(function ($complex) {
                    return [
                        'id' => $complex->id,
                        'name' => $complex->residentialComplex ? $complex->residentialComplex->name : null,
                    ];
                }) : [],

                // Addresses completo
                'addresses' => $user->addresses,
            ];
        });

        return response()->json($formatted);
    }

    // Actualizar usuario
    /**
     * Actualiza el perfil del usuario autenticado.
     *
     * Antes tomaba el user_id del cuerpo y actualizaba a cualquiera: con una
     * sesión válida se podían editar los datos de otra persona. Además dejaba
     * cambiar `rol`, lo que permitía convertirse en tendero o administrador.
     * Ahora solo se edita el propio perfil y solo los campos de contacto; el
     * rol, la calificación y el estado se gestionan por sus propios flujos.
     */
    public function update(ActualizarPerfilRequest $request)
    {
        $user = $request->user();

        /*
         * CAMBIAR EL CORREO PIDE LA CONTRASEÑA.
         *
         * El correo es por donde se recupera la cuenta: quien encontrara una
         * sesión abierta un minuto podía poner el suyo, pedir «olvidé mi
         * contraseña» y quedarse con la cuenta. Nombre, teléfono y dirección
         * se siguen cambiando sin ella, y mandar el MISMO correo de siempre
         * —lo que hacen las versiones publicadas de la app al guardar el
         * perfil— tampoco la pide.
         */
        $correoNuevo = $request->filled('email')
            && mb_strtolower(trim($request->input('email'))) !== mb_strtolower((string) $user->email);

        if ($correoNuevo) {
            $clave = (string) $request->input('current_password', '');

            if ($clave === '' || ! Hash::check($clave, $user->password)) {
                $texto = $clave === ''
                    ? 'Para cambiar el correo escribe tu contraseña.'
                    : 'La contraseña no es correcta.';

                return response()->json([
                    'message' => $texto,
                    'errors' => ['current_password' => [$texto]],
                ], 422);
            }
        }

        /*
         * El correo vacío NO borra el correo. Con `only()` a secas, mandar
         * `email: ""` lo dejaba en null sin pedir la contraseña —el correo es
         * por donde se recupera la cuenta—. Sólo se cambia cuando viene uno.
         */
        $cambios = $request->only(['name', 'phone', 'address']);
        if ($request->filled('email')) {
            $cambios['email'] = $request->input('email');
        }
        $user->update($cambios);

        return response()->json($user);
    }

    /**
     * Cambia la contraseña del usuario autenticado.
     *
     * Se exige la contraseña actual: sin eso, quien tuviera el teléfono
     * desbloqueado un momento podría dejar al dueño fuera de su cuenta.
     */
    public function updatePassword(CambiarContrasenaRequest $request)
    {

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta',
                'errors' => [
                    'current_password' => ['La contraseña actual no es correcta'],
                ],
            ], 422);
        }

        if (Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'La nueva contraseña debe ser distinta a la actual',
                'errors' => [
                    'password' => ['La nueva contraseña debe ser distinta a la actual'],
                ],
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Se cierran las demás sesiones: si la contraseña se cambió porque
        // alguien más tenía acceso, ese acceso debe terminar aquí.
        $actual = $request->user()->currentAccessToken();
        $user->tokens()->where('id', '!=', $actual?->id)->delete();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    // Eliminar usuario
    public function desactivate(DesactivarCuentaRequest $request)
    {

        $user = User::where('email', $request->email)->firstOrFail();

        /*
         * BAJA DE LA PROPIA CUENTA, no de la de otro.
         *
         * Bastaba con saber un correo —y el de cualquier tienda esta
         * publicado— para dejar a esa persona fuera. Uno por uno se podia
         * apagar la plataforma entera, administradores incluidos.
         */
        if ($no = $this->negarCuentaAjena($request, $user->user_id)) {
            return $no;
        }

        if ($user->state === true) {
            $user->state = false;
            $user->save();

            return response()->json(['message' => 'Usuario desactivado correctamente']);
        }

        return response()->json(['message' => 'El usuario ya está desactivado'], 200);
    }

    // ----------------------------
    // Notificaciones del usuario
    // ----------------------------
}
