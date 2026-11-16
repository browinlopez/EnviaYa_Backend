<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BorrarResenaRequest;
use App\Http\Requests\Admin\BorrarResenasEnLoteRequest;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Queries\ResenasDelPanel;
use App\Support\ListadoPaginado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResenasApiController extends Controller
{
    /*
     * `mapaResenas()` y `resumenDeResenas()` SE MUDARON a
     * `App\Queries\ResenasDelPanel` el 14 de noviembre de 2026, con la
     * consulta polimorfica entera. §12 pide las consultas complejas en
     * `Queries/`, y esta era la de forma mas dificil: tres tablas que no
     * tienen la misma forma, cuatro uniones que cambian segun el ambito y
     * tres filtros.
     *
     * El mapa es PUBLICO alli porque los dos borrados de abajo tambien lo
     * necesitan: tenerlo privado aqui era lo que ataba las tres cosas al
     * mismo fichero.
     */

    public function reviews(Request $request)
    {
        $scope = $request->query('scope', 'business');
        $resenas = new ResenasDelPanel;
        $m = $resenas->mapa($scope);

        $q = $resenas->listado(
            $scope,
            negocio: ((int) $request->query('business_id')) ?: null,
            puntaje: $request->query('score'),
        );

        $r = ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['r.comment', 'au.name'],
            ordenables: $resenas->ordenables($scope),
            ordenPorDefecto: 'reviews_id',
            resumen: fn ($f) => $resenas->resumen($f),
        );

        // `user_reviews` no tiene created_at en el esquema; se envía nulo en
        // vez de omitir la clave para que el panel no tenga que adivinar.
        if (! $m['has_dates']) {
            $r['data'] = collect($r['data'])->map(function ($f) {
                $f->created_at = null;

                return $f;
            });
        }

        return response()->json($r);
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
                'business' => BusinessReview::recalcularPromedio($id),
                'domiciliary' => DomiciliaryReview::recalcularPromedio($id),
                default => null,
            };
        }
    }

    public function deleteReview(BorrarResenaRequest $request)
    {
        $datos = $request->validated();

        $m = (new ResenasDelPanel)->mapa($datos['scope']);

        // A quién pertenece la reseña, ANTES de borrarla: después ya no hay de
        // dónde sacarlo para volver a promediar.
        $destino = DB::table($m['table'])
            ->where('reviews_id', $datos['reviews_id'])
            ->value($m['target_key']);

        $borradas = DB::table($m['table'])->where('reviews_id', $datos['reviews_id'])->delete();
        abort_if(! $borradas, 404, 'La reseña no existe.');

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
    public function deleteReviews(BorrarResenasEnLoteRequest $request)
    {
        $datos = $request->validated();

        $m = (new ResenasDelPanel)->mapa($datos['scope']);

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
            'deleted' => $borradas,
            'requested' => $pedidas,
        ]);
    }

    /* ==================================================================
       CATEGORÍAS
       ================================================================== */
}
