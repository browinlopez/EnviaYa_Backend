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
 * El alta, la edicion y la ficha de una persona desde el panel. Van juntas porque  termina devolviendo la ficha, y porque las reglas de que rol puede convivir con que area y que conjunto se tocan entre si.
 */
class AltasYCambiosDeUsuario
{
    /**
     * Crea un usuario de cualquier rol.
     *
     * El correo se marca como verificado en el acto: lo está dando de alta un
     * administrador, y dejarlo sin verificar lo bloquearía al iniciar sesión
     * (el login rechaza cuentas sin verificar).
     *
     * El rol no es un campo suelto: arrastra la fila que lo hace servir. Quién
     * necesita qué vive en `VinculosDelRol`, compartido con `updateUser` — la
     * puerta de crear y la de editar hacían cosas distintas, y la de editar no
     * hacía ninguna.
     */
    public function storeUser(Request $request)
    {
        $datos = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:user,email',
            'password' => 'required|string|min:6',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string|max:255',
            'rol'      => 'required|integer|exists:rol,rol_id',
            'document' => 'nullable|string|max:50',
            'complex_id'   => $this->reglaConjunto('rol'),
            'area_id'      => $this->reglaArea('rol'),
            'access_level' => ['nullable', Rule::in(Area::NIVELES)],
        ]);

        $rol = (int) $datos['rol'];

        $id = DB::transaction(function () use ($datos, $rol, $request) {
            $userId = DB::table('user')->insertGetId([
                'name'              => $datos['name'],
                'email'             => $datos['email'],
                'password'          => Hash::make($datos['password']),
                'phone'             => $datos['phone'] ?? null,
                'address'           => $datos['address'] ?? null,
                'rol'               => $rol,
                'state'             => 1,
                'qualification'     => 0,
                'email_verified_at' => now(),
                // Solo el personal interno tiene área; para los demás la
                // columna se queda nula y el panel les está cerrado igual.
                'area_id'      => $rol === VinculosDelRol::ROL_PERSONAL ? ($datos['area_id'] ?? null) : null,
                'access_level' => $rol === VinculosDelRol::ROL_PERSONAL
                    ? ($datos['access_level'] ?? Area::NIVEL_GESTOR)
                    : Area::NIVEL_GESTOR,
                // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                // es el reloj del servidor, que en el VPS no es el de Bogotá.
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $vinculos = app(VinculosDelRol::class);
            $vinculos->crearPerfilSiFalta($userId, $rol, $datos['document'] ?? null);

            if (in_array($rol, VinculosDelRol::ROLES_DE_CONJUNTO, true)) {
                $vinculos->ponerEnConjunto(
                    $userId,
                    (int) $datos['complex_id'],
                    $rol,
                    $request->user()?->user_id,
                );
            }

            return $userId;
        });

        return response()->json(['message' => 'Usuario creado.', 'user_id' => $id], 201);
    }

    /**
     * Edita un usuario, y con él los vínculos que su rol arrastra.
     *
     * CAMBIAR EL ROL NO ERA UN CAMBIO DE CAMPO. Antes esto hacía un
     * `update` del número y nada más, con dos consecuencias comprobadas:
     *
     *  · subir a alguien a rol 5 lo dejaba SIN fila en `complex_staff` — la
     *    cuenta entraba al panel de aliados y no veía nada, en silencio. Era el
     *    mismo fallo que se había corregido al crear, entrando por la otra
     *    puerta;
     *  · bajarle el rol a un tendero o a un dueño de conjunto NO le quitaba el
     *    acceso: `GET /v1/negocio/me` y `GET /v1/conjunto/residentes` seguían
     *    respondiendo 200, porque las dos puertas resuelven por la fila de
     *    vínculo y no miraban el rol.
     *
     * Lo segundo se cerró además en los middlewares, que es donde tiene que
     * estar la defensa. Acá se cierra el origen: el rol y sus filas se mueven
     * juntos o no se mueve ninguno.
     */
    public function updateUser(Request $request, $id)
    {
        $actual = DB::table('user')->where('user_id', $id)->first();
        abort_if(!$actual, 404, 'El usuario no existe.');

        $datos = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('user', 'email')->ignore($id, 'user_id')],
            'phone'    => 'sometimes|nullable|string|max:20',
            'address'  => 'sometimes|nullable|string|max:255',
            'rol'      => 'sometimes|integer|exists:rol,rol_id',
            'state'    => 'sometimes|boolean',
            'password' => 'sometimes|string|min:6',
            'document' => 'sometimes|nullable|string|max:50',
            /*
             * Acá NO se usa `reglaConjunto()`: esa exige el conjunto cada vez
             * que llega un `rol`, y el panel manda el rol siempre —también
             * cuando solo se corrigió el teléfono—. Editar a un dueño que ya
             * tiene su conjunto habría empezado a pedirlo sin motivo. Se exige
             * más abajo, y solo cuando el rol CAMBIA y no hay ficha de la que
             * heredarlo.
             */
            'complex_id'   => 'nullable|integer|exists:residential_complexes,complex_id',
            'area_id'      => 'nullable|integer|exists:areas,id',
            'access_level' => ['nullable', Rule::in(Area::NIVELES)],
        ]);

        $rolNuevo   = array_key_exists('rol', $datos) ? (int) $datos['rol'] : null;
        $cambiaRol  = $rolNuevo !== null && $rolNuevo !== (int) $actual->rol;
        $sePropioId = (int) $id === (int) $request->user()->user_id;

        /*
         * Los dos vínculos que el rol nuevo necesita para que la cuenta sirva.
         * Si ya los tiene se heredan; si no, se piden. Lo que no puede pasar es
         * que el cambio salga adelante sin ellos, que es lo que hacía antes.
         */
        $fichaPrevia = ComplexStaff::where('user_id', $id)->first();
        $conjuntoNuevo = $datos['complex_id'] ?? $fichaPrevia?->complex_id;

        if ($cambiaRol && in_array($rolNuevo, VinculosDelRol::ROLES_DE_CONJUNTO, true) && !$conjuntoNuevo) {
            return response()->json([
                'message' => 'Elige a qué conjunto pertenece. Sin él la cuenta entra al panel '
                    . 'de aliados y no ve nada, porque todo se acota por ese vínculo.',
            ], 422);
        }

        if ($cambiaRol && $rolNuevo === VinculosDelRol::ROL_PERSONAL
            && !($datos['area_id'] ?? $actual->area_id)) {
            return response()->json([
                'message' => 'Elige un área. Una cuenta de personal interno sin área no puede '
                    . 'entrar al panel: el login la rechaza.',
            ], 422);
        }

        // Un administrador no puede quitarse a sí mismo el rol: si se
        // equivoca queda sin acceso al panel y hay que arreglarlo a mano en
        // la base.
        if ($cambiaRol && $sePropioId && $rolNuevo !== 4) {
            return response()->json([
                'message' => 'No puedes cambiar tu propio rol de administrador.',
            ], 422);
        }

        $vinculos = app(VinculosDelRol::class);

        if ($cambiaRol && ($motivo = $vinculos->impedimento((int) $id, $rolNuevo))) {
            return response()->json(['message' => $motivo], 422);
        }

        if (isset($datos['password'])) {
            $datos['password'] = Hash::make($datos['password']);
        }

        // El documento y el conjunto no son columnas de `user`: viajan en la
        // misma petición y se aplican en otra tabla.
        $documento = $datos['document'] ?? null;
        $conjunto  = $conjuntoNuevo;
        $areaPedida = $datos['area_id'] ?? null;
        $nivelPedido = $datos['access_level'] ?? null;
        unset($datos['document'], $datos['complex_id'], $datos['area_id'], $datos['access_level']);

        /*
         * EL ÁREA SOLO SE TOCA ACÁ CUANDO EL ROL CAMBIA.
         *
         * Para todo lo demás está Control → Áreas, que tiene las salvaguardas
         * que esta pantalla no: no dejarse a uno mismo sin área, no bajarse el
         * propio nivel a consulta. Aceptar `area_id` de forma general por acá
         * habría sido una segunda puerta a lo mismo, sin esas comprobaciones.
         *
         * Y dejar de ser personal interno es dejar de tener área: si se quedara
         * puesta, devolverle el rol 4 le devolvería en silencio los permisos que
         * tenía antes.
         */
        if ($cambiaRol) {
            $esPersonal = $rolNuevo === VinculosDelRol::ROL_PERSONAL;

            $datos['area_id'] = $esPersonal ? ($areaPedida ?? $actual->area_id) : null;

            if ($esPersonal) {
                $datos['access_level'] = $nivelPedido
                    ?? $actual->access_level
                    ?? Area::NIVEL_GESTOR;
            }
        }

        $apagando = array_key_exists('state', $datos) && !$datos['state'] && (int) $actual->state === 1;

        DB::transaction(function () use ($datos, $id, $rolNuevo, $cambiaRol, $conjunto, $documento, $apagando, $vinculos, $request) {
            if ($datos) {
                DB::table('user')->where('user_id', $id)->update($datos);
            }

            if ($cambiaRol) {
                $vinculos->cerrarVinculos((int) $id, $rolNuevo);
                $vinculos->crearPerfilSiFalta((int) $id, $rolNuevo, $documento);

                if (in_array($rolNuevo, VinculosDelRol::ROLES_DE_CONJUNTO, true)) {
                    $vinculos->ponerEnConjunto(
                        (int) $id,
                        (int) $conjunto,
                        $rolNuevo,
                        $request->user()?->user_id,
                    );
                }
            }

            /*
             * SE LE CIERRAN LAS SESIONES ABIERTAS.
             *
             * Apagar la cuenta bloquea el login, no la sesión que ya tenía: sin
             * esto, a quien se acaba de desactivar le seguía sirviendo su token
             * hasta que caducara. Comprobado: `GET /v1/profile` devolvía 200
             * después de apagarlo. Lo mismo al cambiar de rol — el token se
             * emitió para una puerta que ya no es la suya.
             */
            if ($apagando || $cambiaRol) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->where('tokenable_id', $id)
                    ->delete();
            }
        });

        return $this->showUser($id);
    }

    public function showUser($id)
    {
        $user = DB::table('user as u')
            ->leftJoin('user as vb', 'vb.user_id', '=', 'u.email_verified_by')
            ->where('u.user_id', $id)
            ->first([
                'u.user_id', 'u.name', 'u.email', 'u.phone', 'u.address', 'u.rol',
                'u.qualification', 'u.state', 'u.email_verified_at',
                'u.email_verified_by', 'vb.name as email_verified_by_name',
            ]);

        abort_if(!$user, 404, 'El usuario no existe.');

        return response()->json($user);
    }

    /**
     * A QUÉ CONJUNTO PERTENECE, cuando el rol es de conjunto.
     *
     * Sin esta fila la cuenta existe, inicia sesión y no ve nada, porque todo
     * el panel de aliados se acota por ella. Un fallo que solo aparece del otro
     * lado y sin ningún mensaje, así que el conjunto se exige acá y no se
     * confía en que la pantalla se acuerde de mandarlo.
     */
    private function reglaConjunto(string $campoRol): array
    {
        return [
            Rule::requiredIf(fn () => in_array(
                (int) request($campoRol),
                VinculosDelRol::ROLES_DE_CONJUNTO,
                true,
            )),
            'nullable',
            'integer',
            'exists:residential_complexes,complex_id',
        ];
    }

    /**
     * EL ÁREA, cuando el rol es el del personal interno.
     *
     * Mismo caso que el conjunto, una puerta más allá: un rol 4 sin área entra
     * al login y se le rechaza con «tu cuenta no tiene un área asignada». La
     * cuenta queda creada y sirviendo para nada hasta que alguien pase por otra
     * pantalla. Se pide donde se crea.
     */
    private function reglaArea(string $campoRol): array
    {
        return [
            Rule::requiredIf(fn () => (int) request($campoRol) === VinculosDelRol::ROL_PERSONAL),
            'nullable',
            'integer',
            'exists:areas,id',
        ];
    }

    public function resumenDeUsuarios($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, '
            COUNT(*) as total,
            SUM(CASE WHEN u.rol = 1 THEN 1 ELSE 0 END) as compradores,
            SUM(CASE WHEN u.rol = 2 THEN 1 ELSE 0 END) as tenderos,
            SUM(CASE WHEN u.rol = 3 THEN 1 ELSE 0 END) as domiciliarios,
            SUM(CASE WHEN u.rol = 4 THEN 1 ELSE 0 END) as personal,
            SUM(CASE WHEN u.email_verified_at IS NULL THEN 1 ELSE 0 END) as sin_verificar,
            SUM(CASE WHEN u.email_verified_by IS NOT NULL THEN 1 ELSE 0 END) as verificados_a_mano
        ');

        return [
            'total'         => (int) ($r->total ?? 0),
            'compradores'   => (int) ($r->compradores ?? 0),
            'tenderos'      => (int) ($r->tenderos ?? 0),
            'domiciliarios' => (int) ($r->domiciliarios ?? 0),
            'personal'      => (int) ($r->personal ?? 0),
            'sinVerificar'  => (int) ($r->sin_verificar ?? 0),
            'aMano'         => (int) ($r->verificados_a_mano ?? 0),
        ];
    }
}
