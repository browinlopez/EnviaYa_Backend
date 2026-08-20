<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Services\Ajustes;
use App\Support\CatalogoDeAjustes;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * AJUSTES DE LA PLATAFORMA
 *
 * Las reglas de la operación y el control de la app móvil, editables desde el
 * panel. Antes vivían solo en `config/services.php`: cambiar la comisión del
 * domiciliario era editar el `.env` del servidor y reiniciar, y la pantalla las
 * mostraba en solo lectura porque no había otra opción.
 *
 * La respuesta trae el CATÁLOGO además de los valores. La pantalla no sabe qué
 * ajustes existen ni de qué tipo es cada uno: se lo dice el servidor, y así
 * agregar un ajuste es una entrada en `CatalogoDeAjustes` y nada más.
 */
class AjustesApiController extends Controller
{
    public function index()
    {
        $valores = Ajustes::todos();
        $puestos = Ajustes::personalizadas();

        $grupos = [];

        foreach (CatalogoDeAjustes::GRUPOS as $clave => $grupo) {
            $grupos[] = [
                'key'      => $clave,
                'title'    => $grupo['titulo'],
                'help'     => $grupo['ayuda'],
                'settings' => [],
            ];
        }

        $porClave = array_column($grupos, null, 'key');

        foreach (CatalogoDeAjustes::todos() as $clave => $def) {
            $porClave[$def['grupo']]['settings'][] = [
                'key'      => $clave,
                'label'    => $def['etiqueta'],
                'type'     => $def['tipo'],
                'help'     => $def['ayuda'],
                'suffix'   => $def['sufijo'] ?? null,
                'value'    => $valores[$clave],
                'default'  => $def['defecto'],
                // Para que la pantalla pueda decir "este lo cambió alguien" y
                // ofrecer volver al valor de fábrica. Sin esto, un ajuste con el
                // mismo valor que su defecto no se distingue de uno intacto.
                'custom'   => in_array($clave, $puestos, true),
            ];
        }

        return response()->json([
            'groups' => array_values($porClave),
            'values' => $valores,
            'audit'  => $this->ultimosCambios(),
        ]);
    }

    /**
     * Quién cambió qué, de lo poco que hay.
     *
     * Va en la misma respuesta y no en el módulo de Auditoría porque acá es donde
     * hace falta: al ver que el reparto está en 30 % la pregunta inmediata es
     * quién lo puso así. Mandar a alguien a buscarlo en otra pantalla es
     * pedirle que no lo mire.
     */
    private function ultimosCambios(): array
    {
        return DB::table('platform_settings as s')
            ->leftJoin('user as u', 'u.user_id', '=', 's.updated_by')
            ->orderByDesc('s.updated_at')
            ->limit(10)
            ->get(['s.key', 's.updated_at', 'u.name as author'])
            ->map(fn ($r) => [
                'key'    => $r->key,
                'label'  => CatalogoDeAjustes::definicion($r->key)['etiqueta'] ?? $r->key,
                'at'     => $r->updated_at,
                'author' => $r->author,
            ])
            ->all();
    }

    /**
     * Guarda los ajustes que vengan.
     *
     * El cuerpo llega ANIDADO por grupo:
     *
     *     { "operacion": { "horas_estancado": 12 }, "app": { "mantenimiento": true } }
     *
     * y no plano con las claves punteadas, porque para Laravel el punto en el
     * nombre de un campo significa "dentro de este arreglo". Con
     * `operacion.horas_estancado` como clave literal, las reglas no encontraban
     * nada que validar: la petición respondía 200 sin guardar ni rechazar nada,
     * que es peor que fallar. Las claves del catálogo ya tienen la forma
     * `grupo.clave`, así que anidar es la forma natural y no hay que escapar
     * nada.
     */
    public function update(Request $request)
    {
        $datos = $request->validate(CatalogoDeAjustes::reglas());

        // De vuelta a claves punteadas, que es como las conoce el catálogo.
        // Todo lo que no esté en él ya quedó fuera: `validate()` solo devuelve
        // lo que tiene regla.
        $cambios = Ajustes::guardar(Arr::dot($datos), $request->user()->user_id ?? null);

        if ($cambios === []) {
            return response()->json([
                'message' => 'No había nada que cambiar.',
                'changes' => [],
            ]);
        }

        $cuantos = count($cambios);

        return response()->json([
            'message' => $cuantos === 1
                ? 'Ajuste guardado.'
                : "{$cuantos} ajustes guardados.",
            'changes' => $cambios,
            'values'  => Ajustes::todos(),
        ]);
    }

    /** Devuelve un ajuste a su valor de fábrica. */
    public function restablecer(Request $request)
    {
        $datos = $request->validate([
            'key' => 'required|string',
        ]);

        abort_if(!CatalogoDeAjustes::existe($datos['key']), 404, 'Ese ajuste no existe.');

        Ajustes::restablecer($datos['key']);

        return response()->json([
            'message' => 'Ajuste devuelto a su valor por defecto.',
            'values'  => Ajustes::todos(),
        ]);
    }
}
