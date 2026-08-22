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
use App\Services\MediaService;
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
            // La ficha del conjunto y sus reglas de portería.
            'perfil'    => ['view' => true, 'manage' => true],
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
            /*
             * Ve la ficha del conjunto pero no la edita: las notas de portería
             * son instrucciones que tiene que tener a la vista, y el nombre y
             * el teléfono de la administración son a quién llamar cuando algo
             * pasa en la puerta. Cambiarlos es del administrador.
             */
            'perfil'    => ['view' => true, 'manage' => false],
        ],
    ];

    public function mio(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');
        $rol       = (string) $request->attributes->get('complex_role');

        $conjunto = ResidentialComplex::find($complexId);

        return response()->json([
            'complex' => $conjunto ? $this->ficha($conjunto) : null,
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

    /**
     * La ficha del conjunto.
     *
     * `gate_notes` va aca y no en una pantalla aparte a proposito: son las
     * instrucciones permanentes de la porteria —«despues de las 10 p.m. no se
     * reciben domicilios», «la torre 7 no tiene ascensor»— y el celador tiene
     * que verlas sin ir a buscarlas.
     */
    private function ficha($c): array
    {
        return [
            'complex_id'           => (int) $c->complex_id,
            'name'                 => $c->name,
            'photo'                => $c->photo,
            'address'              => $c->address,
            'phone'                => $c->phone,
            'email'                => $c->email,
            'admin_name'           => $c->admin_name,
            'nit'                  => $c->nit,
            'gate_notes'           => $c->gate_notes,
            'require_authorization' => (bool) $c->require_authorization,
            'towers_count'         => $c->towers_count,
            'apartments_per_tower' => $c->apartments_per_tower,
            'latitude'             => $c->latitude !== null ? (float) $c->latitude : null,
            'longitude'            => $c->longitude !== null ? (float) $c->longitude : null,
        ];
    }

    /* ---------------------- PERFIL Y AJUSTES ------------------------- */

    /**
     * El administrador corrige la ficha de su conjunto.
     *
     * NO puede tocar `towers_count` ni `apartments_per_tower`: de ahi salen
     * las unidades, y de las unidades sale la penetracion con la que la
     * plataforma dimensiona el edificio. Que el propio conjunto pueda cambiar
     * el denominador de su propia metrica la vuelve un dato declarado.
     *
     * Tampoco `latitude`/`longitude`: de ahi se heredan las coordenadas de
     * cada direccion al registrarse, asi que moverlas mueve entregas de gente
     * que ya vive ahi.
     */
    public function actualizar(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $datos = $request->validate([
            'name'       => 'required|string|max:255',
            'address'    => 'nullable|string|max:255',
            'phone'      => 'nullable|string|max:40',
            'email'      => 'nullable|email|max:120',
            'admin_name' => 'nullable|string|max:120',
            'nit'        => 'nullable|string|max:40',
            'gate_notes' => 'nullable|string|max:2000',
            'require_authorization' => 'nullable|boolean',
        ]);

        DB::table('residential_complexes')
            ->where('complex_id', $complexId)
            ->update($datos);

        return response()->json([
            'message' => 'Datos del conjunto actualizados.',
            'complex' => $this->ficha(ResidentialComplex::find($complexId)),
        ]);
    }

    /**
     * La foto de la fachada.
     *
     * Pasa por `MediaService`, que es el mismo camino que usan los logos de
     * negocio: sube al bucket, registra el archivo y —desde ahora— escribe la
     * URL en `residential_complexes.photo`. Una segunda forma de subir
     * imagenes solo para esto seria otra cosa que mantener sin ganar nada.
     */
    public function subirFoto(Request $request, MediaService $medios)
    {
        $request->validate([
            // Solo imagenes: es una fachada, no un plano. El servicio corta a
            // 8 MB de todas formas.
            'file' => 'required|file|image|max:8192',
        ]);

        $complexId = (int) $request->attributes->get('complex_id');
        $conjunto  = ResidentialComplex::find($complexId);

        if (!$medios->configurado()) {
            return response()->json([
                'message' => 'El almacenamiento de archivos no esta configurado en el servidor.',
            ], 503);
        }

        try {
            $archivo = $medios->subir(
                'conjuntos',
                $complexId,
                $conjunto->name ?? null,
                $request->file('file'),
                'logo',
                $request->user()->user_id,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Foto actualizada.',
            'photo'   => $archivo['url'],
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
    /**
     * Quiénes son, no cuántos: el detalle por torre y apartamento.
     *
     * SIN CORREO NI TELÉFONO, y no es un olvido. Una administración lleva
     * legítimamente el registro de quién vive en su edificio —eso es lo que se
     * enseña acá—, pero los datos de CONTACTO de cada vecino son de su
     * relación con la plataforma, no con el conjunto. Un listado con teléfonos
     * de 2.000 hogares es una base de datos de mercadeo, no un registro de
     * residentes.
     *
     * Paginado desde el servidor: un conjunto de 2.000 unidades no cabe en una
     * respuesta, y traerlo entero para enseñar veinte filas es tráfico y
     * memoria por nada.
     */
    public function residentesDetalle(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $porPagina = min(100, max(10, (int) $request->query('per_page', 20)));

        $consulta = DB::table('buyer_complex as bc')
            ->where('bc.complex_id', $complexId)
            ->join('buyer as b', 'b.buyer_id', '=', 'bc.buyer_id')
            ->join('user as u', 'u.user_id', '=', 'b.user_id')
            ->leftJoin('user_address as ua', function ($j) use ($complexId) {
                $j->on('ua.user_id', '=', 'b.user_id')
                    ->where('ua.complex_id', '=', $complexId);
            });

        if ($torre = $request->query('tower')) {
            // `sin` es la torre de quienes se registraron antes de que el
            // campo existiera: no es un valor, es su ausencia.
            if ($torre === 'sin') {
                $consulta->whereNull('ua.tower');
            } else {
                $consulta->where('ua.tower', $torre);
            }
        }

        if ($buscar = trim((string) $request->query('search'))) {
            $consulta->where(function ($q) use ($buscar) {
                $q->where('u.name', 'like', "%{$buscar}%")
                    ->orWhere('ua.apartment', 'like', "%{$buscar}%");
            });
        }

        $pagina = $consulta
            ->orderByRaw('ua.tower IS NULL, ua.tower')
            ->orderByRaw('CAST(ua.apartment AS UNSIGNED), ua.apartment')
            ->select([
                'b.buyer_id',
                'u.name',
                'ua.tower',
                'ua.apartment',
                // Desde cuándo usa la plataforma. Es lo que distingue a un
                // residente de siempre de uno que acaba de llegar.
                'b.state',
            ])
            ->distinct()
            ->paginate($porPagina);

        return response()->json([
            'data' => $pagina->items(),
            'meta' => [
                'page'      => $pagina->currentPage(),
                'per_page'  => $pagina->perPage(),
                'total'     => $pagina->total(),
                'last_page' => $pagina->lastPage(),
            ],
        ]);
    }

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
