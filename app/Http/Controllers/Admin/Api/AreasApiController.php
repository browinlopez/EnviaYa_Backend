<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Services\Ajustes;
use App\Support\PanelModules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GESTOR DE ÁREAS Y ACCESOS
 *
 * Solo lo alcanza Tecnología: el módulo `areas` es la llave que reparte todas
 * las demás, y concederlo a otra área equivale a conceder el panel entero.
 *
 * Hay dos protecciones que el panel no puede garantizar y por eso viven acá:
 * el área de sistema no se puede tocar ni borrar, y nadie puede quitarse a sí
 * mismo el acceso al gestor. Cualquiera de las dos, hecha por descuido, deja la
 * instalación sin nadie capaz de arreglarla.
 */
class AreasApiController extends Controller
{
    /* ==================================================================
       LO QUE PUEDE EL USUARIO ACTUAL
       ================================================================== */

    /**
     * Permisos de quien pide, para que el panel dibuje su menú.
     *
     * Se sirve aparte de `/profile` porque el panel lo vuelve a pedir cuando
     * cambia el área de alguien: mezclarlo con el perfil obligaría a recargar
     * la sesión entera para refrescar un menú.
     */
    public function mios(Request $request)
    {
        $user = $request->user();
        $area = $user->area_id ? Area::find($user->area_id) : null;
        $nivel = $user->access_level ?? Area::NIVEL_GESTOR;

        return response()->json([
            'area' => $area ? [
                'id'        => $area->id,
                'code'      => $area->code,
                'name'      => $area->name,
                'is_system' => $area->is_system,
            ] : null,
            'access_level' => $nivel,
            /*
             * Ya vienen recortados por el nivel. El panel no necesita saber
             * que existen los niveles: recibe menos permisos y oculta menos
             * botones, sin una segunda regla que pueda discrepar del servidor.
             */
            'permissions' => $area ? $area->permisos($nivel) : [],
            'catalog'     => PanelModules::paraPanel(),
            /*
             * Las REGLAS VIGENTES de la operación, para todo el panel.
             *
             * El panel las tenía copiadas en `lib/constants.js` porque vivían en
             * el `.env` y no cambiaban nunca. Desde que se editan, esa copia se
             * queda vieja en cuanto alguien las toca: la pantalla de
             * Domiciliarios diría "máximo 3 entregas" mientras el servidor ya
             * permite cinco, y nadie entendería por qué una fila no aparece
             * marcada en el tope.
             *
             * Van acá y no en `/admin/settings` porque ese endpoint pide el
             * módulo `ajustes`, que es solo de Tecnología, y estas cifras las
             * necesita cualquiera que mire Domiciliarios o el panel de inicio.
             * Solo son las de OPERACIÓN: el control de la app no lo pinta nadie.
             */
            'rules' => [
                'domiciliary_share'      => (float) Ajustes::valor('operacion.reparto_domiciliario'),
                'max_active_deliveries'  => (int) Ajustes::valor('operacion.entregas_simultaneas'),
                'stalled_hours'          => (int) Ajustes::valor('operacion.horas_estancado'),
            ],
        ]);
    }

    /* ==================================================================
       ÁREAS
       ================================================================== */

    public function index()
    {
        $areas = Area::orderByDesc('is_system')->orderBy('name')->get();

        // Cuántas personas hay en cada área, de una sola consulta: pedirlo por
        // fila sería una consulta por área en cada carga de la pantalla.
        $personas = DB::table('user')
            ->whereNotNull('area_id')
            ->groupBy('area_id')
            ->pluck(DB::raw('COUNT(*)'), 'area_id');

        return response()->json([
            'catalog' => PanelModules::paraPanel(),
            'areas'   => $areas->map(fn (Area $a) => [
                'id'          => $a->id,
                'code'        => $a->code,
                'name'        => $a->name,
                'description' => $a->description,
                'is_system'   => $a->is_system,
                'state'       => $a->state,
                'users_count' => (int) ($personas[$a->id] ?? 0),
                'permissions' => $a->permisos(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name'        => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
        ]);

        $codigo = $this->codigoUnico($datos['name']);

        $area = Area::create([
            'code'        => $codigo,
            'name'        => $datos['name'],
            'description' => $datos['description'] ?? null,
            'state'       => 1,
        ]);

        $area->guardarPermisos($request->input('permissions', []));

        return response()->json(['message' => 'Área creada.', 'id' => $area->id], 201);
    }

    public function update(Request $request, $id)
    {
        $area = Area::findOr($id, fn () => abort(404, 'El área no existe.'));

        if ($area->is_system) {
            return response()->json([
                'message' => 'El área de Tecnología no se puede modificar: es la que reparte los permisos de las demás.',
            ], 422);
        }

        $datos = $request->validate([
            'name'        => 'sometimes|string|max:80',
            'description' => 'sometimes|nullable|string|max:255',
            'state'       => 'sometimes|boolean',
            'permissions' => 'sometimes|array',
        ]);

        // Quedarse sin acceso al gestor es irreversible desde la interfaz: el
        // panel dejaría de mostrar la pantalla que haría falta para deshacerlo.
        if ($request->has('permissions') && $this->sePerderiaElGestor($request->user(), $area, $request->input('permissions'))) {
            return response()->json([
                'message' => 'No puedes quitarle el acceso a "Roles y accesos" a tu propia área: nadie podría volver a repartir permisos desde el panel.',
            ], 422);
        }

        $area->update(array_intersect_key($datos, array_flip(['name', 'description', 'state'])));

        if ($request->has('permissions')) {
            $area->guardarPermisos($request->input('permissions'));
        }

        return response()->json(['message' => 'Área actualizada.']);
    }

    public function destroy(Request $request, $id)
    {
        $area = Area::findOr($id, fn () => abort(404, 'El área no existe.'));

        if ($area->is_system) {
            return response()->json([
                'message' => 'El área de Tecnología no se puede eliminar.',
            ], 422);
        }

        if ((int) $request->user()->area_id === (int) $area->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia área.',
            ], 422);
        }

        $personas = DB::table('user')->where('area_id', $area->id)->count();
        if ($personas) {
            return response()->json([
                'message' => "No se puede eliminar: {$personas} persona(s) pertenecen a esta área. Reasígnalas primero.",
            ], 422);
        }

        $area->delete();

        return response()->json(['message' => 'Área eliminada.']);
    }

    /* ==================================================================
       PERSONAS DEL EQUIPO
       ================================================================== */

    /**
     * Quién entra al panel y con qué área.
     *
     * Solo el personal (rol 4): compradores y domiciliarios no tienen área y
     * listarlos acá sería mezclar dos poblaciones que no se administran igual.
     */
    public function miembros()
    {
        return response()->json(
            DB::table('user as u')
                ->leftJoin('areas as a', 'a.id', '=', 'u.area_id')
                ->where('u.rol', 4)
                ->orderBy('u.name')
                ->get([
                    'u.user_id', 'u.name', 'u.email', 'u.state',
                    'u.area_id', 'u.access_level',
                    'a.name as area_name', 'a.code as area_code',
                ])
        );
    }

    public function asignarArea(Request $request, $userId)
    {
        $datos = $request->validate([
            'area_id'      => 'sometimes|nullable|integer|exists:areas,id',
            'access_level' => 'sometimes|in:gestor,consulta',
        ]);

        $usuario = DB::table('user')->where('user_id', $userId)->first();
        abort_if(!$usuario, 404, 'El usuario no existe.');

        if ((int) $usuario->rol !== 4) {
            return response()->json([
                'message' => 'Solo el personal de la empresa puede tener un área.',
            ], 422);
        }

        $esUnoMismo = (int) $request->user()->user_id === (int) $userId;

        // Quitarse el área a uno mismo es cerrarse la puerta desde dentro.
        if ($esUnoMismo && array_key_exists('area_id', $datos) && empty($datos['area_id'])) {
            return response()->json([
                'message' => 'No puedes quitarte tu propia área: perderías el acceso al panel.',
            ], 422);
        }

        /*
         * Tampoco degradarse a uno mismo. Pasar a consulta apaga la gestión de
         * TODO, incluido este gestor: la persona quedaría sin poder devolverse
         * el nivel, y si es la única de Tecnología nadie más podría hacerlo.
         */
        if ($esUnoMismo && ($datos['access_level'] ?? null) === Area::NIVEL_CONSULTA) {
            return response()->json([
                'message' => 'No puedes ponerte a ti mismo en solo consulta: perderías la capacidad de volver a cambiarlo.',
            ], 422);
        }

        $cambios = [];
        if (array_key_exists('area_id', $datos)) {
            $cambios['area_id'] = $datos['area_id'] ?? null;
        }
        if (array_key_exists('access_level', $datos)) {
            $cambios['access_level'] = $datos['access_level'];
        }

        if (!$cambios) {
            return response()->json(['message' => 'No hay nada que cambiar.'], 422);
        }

        DB::table('user')->where('user_id', $userId)->update($cambios);

        return response()->json(['message' => 'Acceso actualizado.']);
    }

    /* ==================================================================
       AUXILIARES
       ================================================================== */

    private function codigoUnico(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'area';
        $codigo = $base;
        $n = 2;

        while (Area::where('code', $codigo)->exists()) {
            $codigo = "{$base}-{$n}";
            $n++;
        }

        return $codigo;
    }

    private function sePerderiaElGestor($usuario, Area $area, array $permisos): bool
    {
        if ((int) $usuario->area_id !== (int) $area->id) {
            return false; // se está editando otra área
        }

        $gestor = $permisos['areas'] ?? null;

        return !($gestor['manage'] ?? false);
    }
}
