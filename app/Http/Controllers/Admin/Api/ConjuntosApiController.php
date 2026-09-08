<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\AdministradorDeConjunto;
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

class ConjuntosApiController extends Controller
{
    public function __construct(
        private readonly AdministradorDeConjunto $administradores,
    ) {
    }


    public function complexes()
    {
        /*
         * El administrador de cada conjunto y cuantos celadores tiene, en dos
         * consultas planas que se cruzan en memoria. `complex_staff` es la
         * tabla que dice CUAL conjunto es de quien: sin fila ahi, una cuenta
         * con rol 5 entra al panel de aliados y no ve absolutamente nada.
         */
        $duenos = DB::table('complex_staff as cs')
            ->join('user as u', 'u.user_id', '=', 'cs.user_id')
            ->where('cs.role', ComplexStaff::DUENO)
            ->get(['cs.complex_id', 'u.user_id', 'u.name', 'u.email', 'u.state'])
            ->keyBy('complex_id');

        $celadores = DB::table('complex_staff')
            ->where('role', ComplexStaff::CELADOR)
            ->where('state', true)
            ->groupBy('complex_id')
            // El COUNT necesita un alias propio: `pluck` pide la columna por
            // nombre y una expresión cruda no lo tiene, así que devolvía cero
            // para todos los conjuntos.
            ->selectRaw('complex_id, COUNT(*) as total')
            ->pluck('total', 'complex_id');

        return response()->json(
            DB::table('residential_complexes as rc')
                ->leftJoin('buyer_complex as bc', 'bc.complex_id', '=', 'rc.complex_id')
                /*
                 * La ficha del conjunto viaja con la lista: el formulario del
                 * panel la abre desde aca y sin estos campos habria que pedir
                 * cada conjunto aparte solo para editarlo.
                 *
                 * Van tambien en el GROUP BY porque la consulta agrupa para
                 * contar residentes, y en MySQL con ONLY_FULL_GROUP_BY una
                 * columna seleccionada y no agrupada tumba la consulta.
                 */
                ->groupBy('rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                    'rc.latitude', 'rc.longitude', 'rc.municipality_id',
                    'rc.towers_count', 'rc.apartments_per_tower',
                    'rc.photo', 'rc.phone', 'rc.email', 'rc.admin_name', 'rc.nit',
                    'rc.gate_notes', 'rc.require_authorization',
                    'm.name', 'dp.id', 'dp.name')
                ->orderBy('rc.name')
                ->leftJoin('municipalities as m', 'm.id', '=', 'rc.municipality_id')
                ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
                ->get([
                    'rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                    'rc.latitude', 'rc.longitude', 'rc.municipality_id',
                    'rc.towers_count', 'rc.apartments_per_tower',
                    'rc.photo', 'rc.phone', 'rc.email', 'rc.admin_name', 'rc.nit',
                    'rc.gate_notes', 'rc.require_authorization',
                    'm.name as municipality_name', 'dp.id as department_id', 'dp.name as department_name',
                    DB::raw('COUNT(bc.buyer_id) as residents_count'),
                ])
                /*
                 * QUIEN ADMINISTRA CADA CONJUNTO VIAJA CON LA LISTA.
                 *
                 * Sin esto el panel no puede decir "este conjunto no tiene a
                 * nadie", que es exactamente el estado en el que nace y el que
                 * hay que ver de un vistazo. Un conjunto sin administrador se
                 * ve idéntico a uno con administrador, y nadie lo nota hasta
                 * que el dueño llama diciendo que no puede entrar.
                 *
                 * Va como transformación en memoria y no como otro join: la
                 * consulta ya agrupa para contar residentes y meterle una
                 * segunda tabla obligaría a añadir sus columnas al GROUP BY.
                 */
                ->map(function ($c) use ($duenos, $celadores) {
                    $d = $duenos[$c->complex_id] ?? null;

                    $c->admin_user_id = $d->user_id ?? null;
                    $c->admin_email   = $d->email   ?? null;
                    $c->admin_account = $d->name    ?? null;
                    $c->admin_active  = $d ? (bool) $d->state : null;
                    $c->guards_count  = $celadores[$c->complex_id] ?? 0;

                    return $c;
                })
        );
    }

    public function storeComplex(Request $request)
    {
        $datos = $request->validate([
            'name'         => 'required|string|max:255',
            'address'      => 'nullable|string|max:255',
            'people_count' => 'nullable|integer|min:0',
            'state'        => 'nullable|boolean',
            // Mismo tratamiento que los negocios: el conjunto se ubica en el
            // mapa y su municipio acota la búsqueda de direcciones.
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            /*
             * Cuántas torres y cuántos apartamentos tiene cada una.
             *
             * Se asume que todas son iguales. Es una simplificación consciente:
             * sirve para dimensionar el conjunto y para ofrecer listas en el
             * registro, no para validar que una dirección exista.
             */
            'towers_count'         => 'nullable|integer|min:1|max:500',
            'apartments_per_tower' => 'nullable|integer|min:1|max:2000',

            /*
             * LA FICHA DEL CONJUNTO.
             *
             * El backend ya guardaba estos campos y el panel de aliados ya los
             * editaba, pero el formulario del ADMIN —que es donde el conjunto
             * NACE— no los mandaba: quedaba dado de alta sin foto, sin
             * telefono y sin a quien llamar, y alguien tenia que entrar por el
             * otro panel a completarlo.
             *
             * La foto va como URL porque MediaService ya sube a Cloudflare y
             * devuelve una: el formulario sube primero y manda el enlace.
             */
            'photo'      => 'nullable|string|max:2048',
            'phone'      => 'nullable|string|max:40',
            'email'      => 'nullable|email|max:120',
            'admin_name' => 'nullable|string|max:120',
            'nit'        => 'nullable|string|max:40',
            'gate_notes' => 'nullable|string',
            /*
             * Que la porteria tenga que pedirle permiso al residente antes de
             * dejar entrar. Nace apagado: encenderlo cambia como trabaja el
             * celador y esa es una decision del conjunto, no un valor por
             * defecto.
             */
            'require_authorization' => 'nullable|boolean',
        ]);

        $id = DB::table('residential_complexes')->insertGetId([
            'name'            => $datos['name'],
            'address'         => $datos['address'] ?? null,
            'people_count'    => $datos['people_count'] ?? 0,
            'state'           => $datos['state'] ?? 1,
            'latitude'        => $datos['latitude'] ?? null,
            'longitude'       => $datos['longitude'] ?? null,
            'municipality_id' => $datos['municipality_id'] ?? null,
            'towers_count'         => $datos['towers_count'] ?? null,
            'apartments_per_tower' => $datos['apartments_per_tower'] ?? null,

            'photo'      => $datos['photo'] ?? null,
            'phone'      => $datos['phone'] ?? null,
            'email'      => $datos['email'] ?? null,
            'admin_name' => $datos['admin_name'] ?? null,
            'nit'        => $datos['nit'] ?? null,
            'gate_notes' => $datos['gate_notes'] ?? null,
            'require_authorization' => $datos['require_authorization'] ?? false,
            // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
            // es el reloj del servidor, que en el VPS no es el de Bogotá.
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return response()->json(['message' => 'Conjunto creado.', 'complex_id' => $id], 201);
    }

    public function updateComplex(Request $request, $id)
    {
        $datos = $request->validate([
            'name'         => 'sometimes|string|max:255',
            'address'      => 'sometimes|nullable|string|max:255',
            'people_count' => 'sometimes|nullable|integer|min:0',
            'state'        => 'sometimes|boolean',
            'latitude'        => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'       => 'sometimes|nullable|numeric|between:-180,180',
            'municipality_id' => 'sometimes|nullable|integer|exists:municipalities,id',
            'towers_count'         => 'sometimes|nullable|integer|min:1|max:500',
            'apartments_per_tower' => 'sometimes|nullable|integer|min:1|max:2000',

            'photo'      => 'sometimes|nullable|string|max:2048',
            'phone'      => 'sometimes|nullable|string|max:40',
            'email'      => 'sometimes|nullable|email|max:120',
            'admin_name' => 'sometimes|nullable|string|max:120',
            'nit'        => 'sometimes|nullable|string|max:40',
            'gate_notes' => 'sometimes|nullable|string',
            'require_authorization' => 'sometimes|boolean',
        ]);

        $n = DB::table('residential_complexes')->where('complex_id', $id)->update($datos);
        abort_if(!$n && !DB::table('residential_complexes')->where('complex_id', $id)->exists(), 404, 'El conjunto no existe.');

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
            !DB::table('residential_complexes')->where('complex_id', $id)->exists(),
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
                    . 'Cámbiales el rol desde Comunidad → Usuarios antes de borrarlo.',
            ], 422);
        }

        $entradas = DB::table('complex_entries')->where('complex_id', $id)->count();
        if ($entradas) {
            return response()->json([
                'message' => "No se puede eliminar: su portería tiene {$entradas} entrada(s) registradas, "
                    . 'y son la constancia de quién entró al conjunto. Desactívalo en su lugar.',
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
            !DB::table('residential_complexes')->where('complex_id', $id)->exists(),
            404,
            'El conjunto no existe.',
        );

        $personal = DB::table('complex_staff as cs')
            ->join('user as u', 'u.user_id', '=', 'cs.user_id')
            ->leftJoin('user as c', 'c.user_id', '=', 'cs.created_by')
            ->where('cs.complex_id', $id)
            // El dueno primero: es el que se busca al abrir la ficha.
            ->orderByRaw("CASE WHEN cs.role = '" . ComplexStaff::DUENO . "' THEN 0 ELSE 1 END")
            ->orderBy('u.name')
            ->get([
                'cs.id', 'cs.role', 'cs.state', 'cs.created_at',
                'u.user_id', 'u.name', 'u.email', 'u.phone',
                'u.state as account_state', 'u.email_verified_at',
                'c.name as created_by_name',
            ]);

        return response()->json([
            'dueno'     => $personal->firstWhere('role', ComplexStaff::DUENO),
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
