<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\ActualizarMiConjuntoRequest;
use App\Http\Requests\Conjunto\SubirFotoDelConjuntoRequest;
use App\Http\Resources\Conjunto\ConjuntoResource;
use App\Models\Buyer\ResidentialComplex;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Rol;
use App\Services\MediaService;
use App\Support\FiltroDeFechas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MiConjuntoController extends Controller
{
    /** Qué puede hacer cada rol. Dos roles, sin matriz configurable. */
    private const PERMISOS = [
        ComplexStaff::DUENO => [
            'resumen' => ['view' => true, 'manage' => false],
            'porteria' => ['view' => true, 'manage' => true],
            'entradas' => ['view' => true, 'manage' => false],
            'residentes' => ['view' => true, 'manage' => false],
            'celadores' => ['view' => true, 'manage' => true],
            // El histórico del edificio es suyo: cuánto pide cada torre, qué
            // domiciliarios entran, cómo se identifican.
            'reportes' => ['view' => true, 'manage' => false],
            // La ficha del conjunto y sus reglas de portería.
            'perfil' => ['view' => true, 'manage' => true],
        ],
        ComplexStaff::CELADOR => [
            /*
             * El celador trabaja en la puerta y su menú es solo eso: la
             * portería y el libro de entradas. No ve quién vive dónde, ni
             * administra cuentas, ni tiene el histórico del edificio.
             *
             * Antes tenía también el Resumen y Mi conjunto en modo lectura,
             * pero le sobraban: lo que pasa hoy ya lo tiene en las cifras de
             * la portería, y las notas de portería salen ahí mismo, junto al
             * nombre del conjunto. Dos entradas de menú que no le servían.
             */
            'porteria' => ['view' => true, 'manage' => true],
            'entradas' => ['view' => true, 'manage' => false],
        ],
    ];

    public function mio(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');
        $rol = (string) $request->attributes->get('complex_role');

        $conjunto = ResidentialComplex::find($complexId);

        return response()->json([
            'complex' => $conjunto ? $this->ficha($conjunto) : null,
            'role' => $rol,
            'permissions' => self::PERMISOS[$rol] ?? [],
            'stats' => [
                'residentes' => DB::table('buyer_complex')
                    ->where('complex_id', $complexId)->count(),
                'entradas_hoy' => FiltroDeFechas::unDia(
                    DB::table('complex_entries')->where('complex_id', $complexId),
                    'created_at',
                    now(),
                )->count(),
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
        return (new ConjuntoResource($c))->resolve();
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
    public function actualizar(ActualizarMiConjuntoRequest $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $datos = $request->validated();

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
    public function subirFoto(SubirFotoDelConjuntoRequest $request, MediaService $medios)
    {

        $complexId = (int) $request->attributes->get('complex_id');
        $conjunto = ResidentialComplex::find($complexId);

        if (! $medios->configurado()) {
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
        } catch (RuntimeException $e) {
            // `MediaService` solo lanza `RuntimeException`, y hasta envuelve el
            // fallo del almacenamiento con un texto legible (`explicar()`).
            // Lo que no sea eso no es culpa de quien sube la foto.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Foto actualizada.',
            'photo' => $archivo['url'],
        ]);
    }
}
