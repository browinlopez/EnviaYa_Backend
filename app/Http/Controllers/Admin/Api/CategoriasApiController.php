<?php

namespace App\Http\Controllers\Admin\Api;

use App\Support\Consultas\AyudasDeAdmin;
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

class CategoriasApiController extends Controller
{
    use AyudasDeAdmin;


    public function categories(Request $request, MediaService $medios)
    {
        $scope = $request->query('scope', 'product');

        if ($scope === 'business') {
            $filas = DB::table('category_business as c')
                ->orderBy('c.name')
                ->select(['c.id', 'c.name', 'c.description', 'c.image'])
                // `business.type` guarda el ID de la categoría de negocio, no
                // su nombre: el join anterior comparaba un entero con un texto
                // y todas las categorías salían con cero negocios.
                ->selectSub(
                    DB::table('business')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('business.type', 'c.id'),
                    'items_count',
                )
                ->selectSub(
                    DB::table('category_category_business')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('category_category_business.business_category_id', 'c.id'),
                    'product_categories_count',
                )
                ->get();

            return response()->json(
                $this->conImagenPrincipal($filas, 'categorias', 'id', 'image', $medios)
            );
        }

        // `category` no tiene columna `image`; se devuelve nula para que el
        // panel use el mismo componente en las dos taxonomías.
        $filas = DB::table('category as c')
            ->orderBy('c.name')
            ->select([
                DB::raw('c.category_id as id'),
                'c.name', 'c.description', 'c.state',
                DB::raw('NULL as image'),
            ])
            ->selectSub(
                DB::table('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.category_id', 'c.category_id'),
                'items_count',
            )
            ->get();

        // A qué categorías de negocio pertenece cada una. Se resuelve en una
        // consulta y se reparte en memoria: unirla a la anterior multiplicaría
        // las filas y falsearía el conteo de productos.
        $vinculos = DB::table('category_category_business as v')
            ->join('category_business as cb', 'cb.id', '=', 'v.business_category_id')
            ->orderBy('cb.name')
            ->get(['v.category_id', 'cb.id', 'cb.name'])
            ->groupBy('category_id');

        return response()->json(
            $filas->map(function ($c) use ($vinculos) {
                $suyas = $vinculos[$c->id] ?? collect();
                $c->business_categories = $suyas->map(fn($v) => ['id' => $v->id, 'name' => $v->name])->values();
                $c->business_category_ids = $suyas->pluck('id')->values();
                return $c;
            })
        );
    }

    /**
     * Reescribe a qué categorías de negocio pertenece una categoría de producto.
     *
     * La columna heredada `category.business_category_id` se deja apuntando a
     * la primera: el backend antiguo todavía la lee y vaciarla dejaría
     * categorías invisibles en la app sin ningún aviso.
     */
    private function sincronizarCategoriasDeNegocio(int $categoryId, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($categoryId, $ids) {
            DB::table('category_category_business')->where('category_id', $categoryId)->delete();

            foreach ($ids as $id) {
                DB::table('category_category_business')->insertOrIgnore([
                    'category_id'          => $categoryId,
                    'business_category_id' => $id,
                ]);
            }

            DB::table('category')->where('category_id', $categoryId)
                ->update(['business_category_id' => $ids[0] ?? null]);
        });
    }

    public function storeCategory(Request $request)
    {
        $datos = $request->validate([
            'scope'                  => 'required|in:product,business',
            'name'                   => 'required|string|max:255',
            'description'            => 'nullable|string',
            'image'                  => 'nullable|string',
            'state'                  => 'sometimes|boolean',
            'business_category_ids'   => 'sometimes|array',
            'business_category_ids.*' => 'integer|exists:category_business,id',
        ]);

        if ($datos['scope'] === 'business') {
            $id = DB::table('category_business')->insertGetId([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'image'       => $datos['image'] ?? null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $id = DB::table('category')->insertGetId([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'state'       => (int) ($datos['state'] ?? 1),
            ]);

            $this->sincronizarCategoriasDeNegocio($id, $datos['business_category_ids'] ?? []);
        }

        return response()->json(['message' => 'Categoría creada.', 'id' => $id], 201);
    }

    public function updateCategory(Request $request)
    {
        $datos = $request->validate([
            'scope'                  => 'required|in:product,business',
            'id'                     => 'required|integer',
            'name'                   => 'required|string|max:255',
            'description'            => 'nullable|string',
            'image'                  => 'nullable|string',
            'state'                  => 'sometimes|boolean',
            'business_category_ids'   => 'sometimes|array',
            'business_category_ids.*' => 'integer|exists:category_business,id',
        ]);

        if ($datos['scope'] === 'business') {
            $existe = DB::table('category_business')->where('id', $datos['id'])->exists();
            abort_if(!$existe, 404, 'La categoría no existe.');

            DB::table('category_business')->where('id', $datos['id'])->update([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'image'       => $datos['image'] ?? null,
                'updated_at'  => now(),
            ]);
        } else {
            $existe = DB::table('category')->where('category_id', $datos['id'])->exists();
            abort_if(!$existe, 404, 'La categoría no existe.');

            // `update()` devuelve 0 cuando no cambia ningún valor, así que la
            // existencia se comprueba aparte: si no, guardar sin tocar nada
            // respondía "no existe".
            DB::table('category')->where('category_id', $datos['id'])->update([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'state'       => (int) ($datos['state'] ?? 1),
            ]);

            $this->sincronizarCategoriasDeNegocio(
                (int) $datos['id'],
                $datos['business_category_ids'] ?? null,
            );
        }

        return response()->json(['message' => 'Categoría actualizada.']);
    }

    public function deleteCategory(Request $request)
    {
        $datos = $request->validate([
            'scope' => 'required|in:product,business',
            'id'    => 'required|integer',
        ]);

        // Borrar una categoría en uso dejaría productos apuntando a un id
        // inexistente; se rechaza con un mensaje que explica el porqué.
        if ($datos['scope'] === 'business') {
            $cat = DB::table('category_business')->where('id', $datos['id'])->first();
            abort_if(!$cat, 404, 'La categoría no existe.');

            // `business.type` guarda el ID, no el nombre.
            $enUso = DB::table('business')->where('type', $cat->id)->count();
            if ($enUso) {
                return response()->json([
                    'message' => "No se puede eliminar: {$enUso} negocio(s) usan esta categoría.",
                ], 422);
            }

            $conCategorias = DB::table('category_category_business')
                ->where('business_category_id', $cat->id)->count();
            if ($conCategorias) {
                return response()->json([
                    'message' => "No se puede eliminar: {$conCategorias} categoría(s) de producto están asignadas a ella.",
                ], 422);
            }

            DB::table('category_business')->where('id', $datos['id'])->delete();
        } else {
            $enUso = DB::table('products')->where('category_id', $datos['id'])->count();
            if ($enUso) {
                return response()->json([
                    'message' => "No se puede eliminar: {$enUso} producto(s) usan esta categoría.",
                ], 422);
            }

            $n = DB::table('category')->where('category_id', $datos['id'])->delete();
            abort_if(!$n, 404, 'La categoría no existe.');
        }

        return response()->json(['message' => 'Categoría eliminada.']);
    }

    /* ==================================================================
       CONJUNTOS RESIDENCIALES
       ================================================================== */
}
