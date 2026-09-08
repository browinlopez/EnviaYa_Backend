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
}
