<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CorregirConjuntoRequest;
use App\Http\Requests\Admin\CrearConjuntoRequest;
use App\Models\Conjunto\ComplexStaff;
use App\Queries\ConjuntosDelPanel;
use App\Services\AdministradorDeConjunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConjuntosApiController extends Controller
{
    public function __construct(
        private readonly AdministradorDeConjunto $administradores,
    ) {}

    /** El listado del panel. La consulta vive en `Queries/ConjuntosDelPanel`. */
    public function complexes()
    {
        return response()->json((new ConjuntosDelPanel)->listado());
    }

    public function storeComplex(CrearConjuntoRequest $request)
    {
        $datos = $request->validated();

        $id = DB::table('residential_complexes')->insertGetId([
            'name' => $datos['name'],
            'address' => $datos['address'] ?? null,
            'people_count' => $datos['people_count'] ?? 0,
            'state' => $datos['state'] ?? 1,
            'latitude' => $datos['latitude'] ?? null,
            'longitude' => $datos['longitude'] ?? null,
            'municipality_id' => $datos['municipality_id'] ?? null,
            'towers_count' => $datos['towers_count'] ?? null,
            'apartments_per_tower' => $datos['apartments_per_tower'] ?? null,

            'photo' => $datos['photo'] ?? null,
            'phone' => $datos['phone'] ?? null,
            'email' => $datos['email'] ?? null,
            'admin_name' => $datos['admin_name'] ?? null,
            'nit' => $datos['nit'] ?? null,
            'gate_notes' => $datos['gate_notes'] ?? null,
            'require_authorization' => $datos['require_authorization'] ?? false,
            // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
            // es el reloj del servidor, que en el VPS no es el de Bogotá.
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Conjunto creado.', 'complex_id' => $id], 201);
    }

    public function updateComplex(CorregirConjuntoRequest $request, $id)
    {
        $datos = $request->validated();

        $n = DB::table('residential_complexes')->where('complex_id', $id)->update($datos);
        abort_if(! $n && ! DB::table('residential_complexes')->where('complex_id', $id)->exists(), 404, 'El conjunto no existe.');

        return response()->json(['message' => 'Conjunto actualizado.']);
    }

    /**
     * Borra un conjunto, y solo si no se lleva nada por delante.
     *
     * Las foráneas de `complex_staff` y `complex_entries` van EN CASCADA, así
     * que borrar la fila del conjunto borraba también, sin decirlo:
     *
     *  · a su administrador y a sus celadores, que quedaban con la cuenta viva
     *    y sin conjunto — entraban al panel de aliados y se les rechazaba;
     *  · **la bitácora entera de la portería**: quién entró, cuándo y con qué
     *    método. Eso es el control, no un dato accesorio, y una pantalla más
     *    allá se argumenta justo lo contrario para no borrar a un celador
     *    («tienen entradas registradas a su nombre»).
     *
     * Se comprueban las tres cosas antes, con el mismo criterio con el que ya
     * se rechaza borrar un área con gente dentro: lo que tiene historial no se
     * desengancha de paso.
     */
    public function deleteComplex($id)
    {
        abort_if(
            ! DB::table('residential_complexes')->where('complex_id', $id)->exists(),
            404,
            'El conjunto no existe.',
        );

        $vinculados = DB::table('buyer_complex')->where('complex_id', $id)->count();
        if ($vinculados) {
            return response()->json([
                'message' => "No se puede eliminar: {$vinculados} usuario(s) están vinculados a este conjunto.",
            ], 422);
        }

        $personal = DB::table('complex_staff')->where('complex_id', $id)->count();
        if ($personal) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$personal} persona(s) a cargo de su portería. "
                    .'Cámbiales el rol desde Comunidad → Usuarios antes de borrarlo.',
            ], 422);
        }

        $entradas = DB::table('complex_entries')->where('complex_id', $id)->count();
        if ($entradas) {
            return response()->json([
                'message' => "No se puede eliminar: su portería tiene {$entradas} entrada(s) registradas, "
                    .'y son la constancia de quién entró al conjunto. Desactívalo en su lugar.',
            ], 422);
        }

        DB::table('residential_complexes')->where('complex_id', $id)->delete();

        return response()->json(['message' => 'Conjunto eliminado.']);
    }

    /* ------------------------------------------------------------------
       EL PERSONAL DEL CONJUNTO

       Un conjunto recien creado no le sirve a nadie: hace falta una persona
       que entre por el panel de aliados a administrarlo, y a esa persona
       habia que crearla por consola (`php artisan conjunto:dueno`). Quien
       da de alta el conjunto desde el panel no tiene acceso al servidor, asi
       que el conjunto se quedaba a medias sin que nada lo dijera.

       Son DOS COSAS que van juntas y por eso no basta con la pantalla de
       usuarios: la cuenta con rol 5, y la ficha en `complex_staff` que dice
       cual conjunto es el suyo. Con la primera sola, la cuenta entra y no ve
       nada.
       ------------------------------------------------------------------ */

    /** El administrador y los celadores de un conjunto. */
    public function complexStaff($id)
    {
        abort_if(
            ! DB::table('residential_complexes')->where('complex_id', $id)->exists(),
            404,
            'El conjunto no existe.',
        );

        $personal = DB::table('complex_staff as cs')
            ->join('user as u', 'u.user_id', '=', 'cs.user_id')
            ->leftJoin('user as c', 'c.user_id', '=', 'cs.created_by')
            ->where('cs.complex_id', $id)
            // El dueno primero: es el que se busca al abrir la ficha.
            ->orderByRaw("CASE WHEN cs.role = '".ComplexStaff::DUENO."' THEN 0 ELSE 1 END")
            ->orderBy('u.name')
            ->get([
                'cs.id', 'cs.role', 'cs.state', 'cs.created_at',
                'u.user_id', 'u.name', 'u.email', 'u.phone',
                'u.state as account_state', 'u.email_verified_at',
                'c.name as created_by_name',
            ]);

        return response()->json([
            'dueno' => $personal->firstWhere('role', ComplexStaff::DUENO),
            'celadores' => $personal->where('role', ComplexStaff::CELADOR)->values(),
        ]);
    }

    /**
     * Nombrar al administrador de un conjunto.
     *
     * La regla vive en `AdministradorDeConjunto`: son 150 lineas con tres
     * decisiones que no se ven leyendo esto —aceptar un correo existente, no
     * quitarle el administrador a otro conjunto, y devolver la contrasena
     * generada una sola vez—.
     */
    public function assignComplexOwner(Request $request, $id)
    {
        return $this->administradores->assignComplexOwner($request, $id);
    }
}
