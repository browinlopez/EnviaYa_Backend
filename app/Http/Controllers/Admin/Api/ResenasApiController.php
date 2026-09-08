<?php

namespace App\Http\Controllers\Admin\Api;

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

class ResenasApiController extends Controller
{

    /** Las tres tablas de reseñas tienen forma distinta; se unifican acá. */
    private function mapaResenas(string $scope): array
    {
        return match ($scope) {
            'business' => [
                'table' => 'business_reviews',
                'target' => ['business', 'busines_id', 'name'],
                'target_key' => 'busines_id',
                'author_key' => 'buyer_id',
                'author_via_buyer' => true,
                'has_dates' => true,
            ],
            'domiciliary' => [
                'table' => 'domiciliary_reviews',
                'target' => ['domiciliary', 'domiciliary_id', null],
                'target_key' => 'domiciliary_id',
                'author_key' => 'buyer_id',
                'author_via_buyer' => true,
                'has_dates' => true,
            ],
            'user' => [
                'table' => 'user_reviews',
                'target' => ['user', 'user_id', 'name'],
                'target_key' => 'user_id',
                'author_key' => 'domiciliary_id',
                'author_via_buyer' => false,
                'has_dates' => false,
            ],
            default => throw new \InvalidArgumentException('Ámbito de reseña no válido.'),
        };
    }

    public function reviews(Request $request)
    {
        $scope = $request->query('scope', 'business');
        $m = $this->mapaResenas($scope);

        $q = DB::table($m['table'] . ' as r');
        $cols = ['r.reviews_id', 'r.qualification', 'r.comment', 'r.state'];

        if ($m['has_dates']) {
            $cols[] = 'r.created_at';
        }

        // Destino de la reseña
        if ($scope === 'domiciliary') {
            $q->leftJoin('domiciliary as t', 't.domiciliary_id', '=', 'r.domiciliary_id')
                ->leftJoin('user as tu', 'tu.user_id', '=', 't.user_id');
            $cols[] = DB::raw('r.domiciliary_id as target_id');
            $cols[] = DB::raw('tu.name as target_name');
        } else {
            [$tabla, $llave, $campoNombre] = $m['target'];
            $q->leftJoin("{$tabla} as t", "t.{$llave}", '=', "r.{$m['target_key']}");
            $cols[] = DB::raw("r.{$m['target_key']} as target_id");
            $cols[] = DB::raw("t.{$campoNombre} as target_name");
        }

        // Autor de la reseña
        if ($m['author_via_buyer']) {
            $q->leftJoin('buyer as ab', 'ab.buyer_id', '=', 'r.buyer_id')
                ->leftJoin('user as au', 'au.user_id', '=', 'ab.user_id');
        } else {
            $q->leftJoin('domiciliary as ad', 'ad.domiciliary_id', '=', 'r.domiciliary_id')
                ->leftJoin('user as au', 'au.user_id', '=', 'ad.user_id');
        }
        $cols[] = DB::raw('au.name as author_name');

        $q->select($cols);

        /*
         * Negocio.
         *
         * Solo tiene sentido en el ámbito de negocios: en el de domiciliarios
         * el destino de la reseña es una persona, no una tienda. Aplicarlo ahí
         * devolvería vacío sin explicar por qué, así que simplemente se ignora.
         */
        if ($scope !== 'domiciliary' && ($negocio = (int) $request->query('business_id'))) {
            $q->where("r.{$m['target_key']}", $negocio);
        }

        // Puntaje: negativas (1-2), positivas (4-5) o con comentario.
        match ($request->query('score')) {
            'negativas' => $q->whereBetween('r.qualification', [1, 2]),
            'positivas' => $q->where('r.qualification', '>=', 4),
            'comentadas' => $q->whereNotNull('r.comment')->where('r.comment', '!=', ''),
            default => null,
        };

        $ordenables = [
            'reviews_id'    => 'r.reviews_id',
            'qualification' => 'r.qualification',
            'target_name'   => 't.name',
        ];

        if ($m['has_dates']) {
            $ordenables['created_at'] = 'r.created_at';
        }

        $r = ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['r.comment', 'au.name'],
            ordenables: $ordenables,
            ordenPorDefecto: 'reviews_id',
            resumen: fn ($f) => $this->resumenDeResenas($f),
        );

        // `user_reviews` no tiene created_at en el esquema; se envía nulo en
        // vez de omitir la clave para que el panel no tenga que adivinar.
        if (!$m['has_dates']) {
            $r['data'] = collect($r['data'])->map(function ($f) {
                $f->created_at = null;
                return $f;
            });
        }

        return response()->json($r);
    }

    private function resumenDeResenas($q): array
    {
        $r = ListadoPaginado::soloAgregados($q, "
            COUNT(*) as total,
            AVG(r.qualification) as promedio,
            SUM(CASE WHEN r.qualification BETWEEN 1 AND 2 THEN 1 ELSE 0 END) as negativas,
            SUM(CASE WHEN r.comment IS NOT NULL AND r.comment <> '' THEN 1 ELSE 0 END) as comentadas
        ");

        return [
            'total'      => (int) ($r->total ?? 0),
            // Sin reseñas el promedio no es 0 estrellas, es que no hay: un 0,0
            // se lee como "todo el mundo la odia".
            'promedio'   => $r && $r->total > 0 ? round((float) $r->promedio, 2) : null,
            'negativas'  => (int) ($r->negativas ?? 0),
            'comentadas' => (int) ($r->comentadas ?? 0),
        ];
    }

    /**
     * Vuelve a promediar la calificación de quien recibió las reseñas.
     *
     * Sin esto, moderar desde el panel quitaba la reseña de la lista y dejaba
     * la nota intacta: borrar una difamatoria de una estrella la hacía
     * desaparecer de la ficha y el negocio se quedaba con el 2,8 que esa misma
     * reseña había provocado, hasta que un cliente nuevo publicara otra.
     *
     * El ámbito `user` queda fuera a propósito. Los dos caminos que hoy
     * calculan esa nota no coinciden entre sí —al crear se guarda en `buyer` y
     * al editar en `user`— así que recalcular acá exigiría antes decidir cuál
     * de los dos es el bueno, y eso es una corrección aparte.
     *
     * @param  list<int>  $destinos  ids de negocio o domiciliario
     */
    private function recalcularCalificacion(string $scope, array $destinos): void
    {
        foreach (array_filter($destinos) as $id) {
            match ($scope) {
                'business'    => BusinessReview::recalcularPromedio($id),
                'domiciliary' => DomiciliaryReview::recalcularPromedio($id),
                default       => null,
            };
        }
    }

    public function deleteReview(Request $request)
    {
        $datos = $request->validate([
            'scope'      => 'required|in:business,domiciliary,user',
            'reviews_id' => 'required|integer',
        ]);

        $m = $this->mapaResenas($datos['scope']);

        // A quién pertenece la reseña, ANTES de borrarla: después ya no hay de
        // dónde sacarlo para volver a promediar.
        $destino = DB::table($m['table'])
            ->where('reviews_id', $datos['reviews_id'])
            ->value($m['target_key']);

        $borradas = DB::table($m['table'])->where('reviews_id', $datos['reviews_id'])->delete();
        abort_if(!$borradas, 404, 'La reseña no existe.');

        $this->recalcularCalificacion($datos['scope'], $destino ? [(int) $destino] : []);

        return response()->json(['message' => 'Reseña eliminada.']);
    }

    /**
     * Retira VARIAS reseñas de una vez.
     *
     * Moderar es un trabajo por lotes: cuando aparece una tanda de comentarios
     * del mismo tipo —insultos, spam de un competidor— hay que quitarlos todos,
     * y de a uno son cuatro clics por cada uno.
     *
     * TOPE de 100 por petición. No es por rendimiento: es porque una acción
     * destructiva sin techo, con un identificador de más por descuido, borra sin
     * límite. Cien es más de lo que nadie selecciona a mano en una pantalla.
     */
    public function deleteReviews(Request $request)
    {
        $datos = $request->validate([
            'scope' => 'required|in:business,domiciliary,user',
            'ids'   => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ]);

        $m = $this->mapaResenas($datos['scope']);

        // Los destinos afectados, antes del borrado. Un lote de diez reseñas
        // puede tocar diez negocios distintos o uno solo.
        $destinos = DB::table($m['table'])
            ->whereIn('reviews_id', $datos['ids'])
            ->pluck($m['target_key'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $borradas = DB::table($m['table'])
            ->whereIn('reviews_id', $datos['ids'])
            ->delete();

        $this->recalcularCalificacion($datos['scope'], $destinos);

        /*
         * Se dice cuántas se borraron DE VERDAD y no cuántas se pidieron. Si
         * alguien ya había quitado dos desde otra pestaña, "se eliminaron 8" con
         * diez seleccionadas es la única respuesta honesta.
         */
        $pedidas = count($datos['ids']);

        return response()->json([
            'message' => $borradas === $pedidas
                ? ($borradas === 1 ? 'Reseña eliminada.' : "Se eliminaron {$borradas} reseñas.")
                : "Se eliminaron {$borradas} de {$pedidas}: el resto ya no existía.",
            'deleted'   => $borradas,
            'requested' => $pedidas,
        ]);
    }

    /* ==================================================================
       CATEGORÍAS
       ================================================================== */
}
