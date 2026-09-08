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

class NegociosApiController extends Controller
{
    use AyudasDeAdmin;


    public function businesses(MediaService $medios)
    {
        /*
         * Los agregados van en subconsultas y NO en joins.
         *
         * Unir `products_business` y `orderssales` a la vez produce un
         * producto cartesiano: cada pedido se repite una vez por producto del
         * negocio. Los COUNT(DISTINCT) sobrevivían, pero SUM() no tiene
         * DISTINCT y las ventas salían multiplicadas por el número de
         * productos (con 186 productos, $53.000 se mostraban como
         * $9.858.000).
         */
        $filas = DB::table('business as b')
                ->orderBy('b.name')
                ->leftJoin('municipalities as m', 'm.id', '=', 'b.municipality_id')
                ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
                // `business.type` guarda el ID de la categoría de negocio.
                ->leftJoin('category_business as cb', 'cb.id', '=', 'b.type')
                ->select([
                    'b.busines_id', 'b.name', 'b.phone', 'b.address', 'b.qualification',
                    'b.razonSocial_DCD', 'b.NIT', 'b.logo', 'b.description',
                    'b.latitude', 'b.longitude', 'b.type', 'b.state',
                    'b.municipality_id', 'm.name as municipality_name',
                    'dp.id as department_id', 'dp.name as department_name',
                    'cb.name as type_name',
                ])
                // El dueño en subconsulta: unir `owner_busines` repetiría la
                // fila del negocio si alguna vez tuviera dos vínculos.
                ->selectSub(
                    DB::table('owner_busines as ob')
                        ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                        ->join('user as u', 'u.user_id', '=', 'o.user_id')
                        ->selectRaw('u.name')
                        ->whereColumn('ob.busines_id', 'b.busines_id')
                        ->limit(1),
                    'owner_name',
                )
                ->selectSub(
                    DB::table('products_business')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('products_business.busines_id', 'b.busines_id'),
                    'products_count',
                )
                ->selectSub(
                    DB::table('orderssales')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('orderssales.busines_id', 'b.busines_id'),
                    'orders_count',
                )
                ->selectSub(
                    DB::table('orderssales')
                        ->selectRaw('COALESCE(SUM(total), 0)')
                        ->whereColumn('orderssales.busines_id', 'b.busines_id')
                        ->where('state', self::ENTREGADO),
                    'revenue',
                )
                ->get();

        return response()->json(
            $this->conImagenPrincipal($filas, 'negocios', 'busines_id', 'logo', $medios)
        );
    }

    public function showBusiness($id)
    {
        $negocio = DB::table('business as b')
            ->leftJoin('municipalities as m', 'm.id', '=', 'b.municipality_id')
            ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
            ->leftJoin('category_business as cb', 'cb.id', '=', 'b.type')
            ->where('b.busines_id', $id)
            ->first([
                'b.*',
                'm.name as municipality_name',
                'dp.id as department_id',
                'dp.name as department_name',
                'cb.name as type_name',
            ]);
        abort_if(!$negocio, 404, 'El negocio no existe.');

        return response()->json($negocio);
    }

    /**
     * Catálogo de departamentos con sus municipios.
     *
     * Va en una sola respuesta (32 departamentos, 110 municipios) porque el
     * formulario necesita ambos niveles a la vez para encadenar los
     * selectores, y pedirlos por separado obligaría a una segunda petición
     * cada vez que se cambia de departamento.
     */
    public function locations()
    {
        $municipios = DB::table('municipalities')
            ->orderBy('name')
            ->get(['id', 'name', 'department_id'])
            ->groupBy('department_id');

        return response()->json(
            DB::table('departments')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(function ($d) use ($municipios) {
                    $d->municipalities = ($municipios[$d->id] ?? collect())
                        ->map(fn($m) => ['id' => $m->id, 'name' => $m->name])
                        ->values();
                    return $d;
                })
                // Un departamento sin municipios cargados no sirve para elegir.
                ->filter(fn($d) => count($d->municipalities) > 0)
                ->values()
        );
    }

    /** Campos editables de un negocio, compartidos por crear y actualizar. */
    private function reglasNegocio(bool $creando): array
    {
        $req = $creando ? 'required' : 'sometimes';

        return [
            'name'            => "{$req}|string|max:255",
            'phone'           => 'sometimes|nullable|string|max:30',
            'address'         => 'sometimes|nullable|string|max:255',
            'description'     => 'sometimes|nullable|string',
            'razonSocial_DCD' => 'sometimes|nullable|string|max:255',
            'NIT'             => 'sometimes|nullable|string|max:50',
            'logo'            => 'sometimes|nullable|string',
            // Es el ID de una categoría de negocio: si se aceptara cualquier
            // valor, la tienda quedaría fuera de todos los carruseles y sus
            // productos no podrían clasificarse en ninguna categoría.
            'type'            => 'sometimes|nullable|integer|exists:category_business,id',
            'state'           => 'sometimes|boolean',
            // El municipio acota la búsqueda de direcciones: sin él, "calle 18
            // carrera 37b" aparece en cualquier ciudad del país.
            'municipality_id' => 'sometimes|nullable|integer|exists:municipalities,id',
            // Coordenadas: el panel las obtiene de un mapa, así que llegan como
            // decimales. Se acotan al rango válido para que un error de captura
            // no deje un punto en mitad del océano.
            'latitude'        => 'sometimes|nullable|numeric|between:-90,90',
            'longitude'       => 'sometimes|nullable|numeric|between:-180,180',
        ];
    }

    public function storeBusiness(Request $request)
    {
        $datos = $request->validate($this->reglasNegocio(true));

        $datos['state'] = $datos['state'] ?? 1;
        $datos['qualification'] = 0;
        // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP` es
        // el reloj del servidor, que en el VPS no es el de Bogotá.
        $datos['created_at'] = now();
        $datos['updated_at'] = now();

        $id = DB::table('business')->insertGetId($datos);

        return response()->json([
            'message' => 'Negocio creado.',
            'busines_id' => $id,
        ], 201);
    }

    public function updateBusiness(Request $request, $id)
    {
        abort_if(!DB::table('business')->where('busines_id', $id)->exists(), 404, 'El negocio no existe.');

        $datos = $request->validate($this->reglasNegocio(false));

        if ($datos) {
            DB::table('business')->where('busines_id', $id)->update($datos);
        }

        return $this->showBusiness($id);
    }


    /**
     * Sube una imagen o documento a la carpeta del negocio.
     *
     * El archivo viaja por el backend y nunca desde el navegador: las llaves
     * de R2 dan permiso de escritura sobre todo el bucket y no pueden salir
     * del servidor.
     */
    public function uploadBusinessMedia(Request $request, $id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name']);
        abort_if(!$negocio, 404, 'El negocio no existe.');

        $request->validate([
            'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,gif,pdf',
            'tipo' => 'sometimes|string|in:logo,galeria,documentos',
        ]);

        $tipo = $request->input('tipo', 'galeria');

        try {
            $subido = $medios->subir($negocio, $request->file('file'), $tipo);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // El logo además queda apuntado en el registro: es el que consume la
        // app móvil.
        if ($tipo === 'logo') {
            DB::table('business')->where('busines_id', $id)->update(['logo' => $subido['url']]);
        }

        return response()->json([
            'message' => 'Archivo cargado.',
            'file' => $subido,
            'folder' => $medios->carpeta($negocio),
            'public' => $medios->tienePublico(),
        ], 201);
    }

    public function businessMedia($id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name']);
        abort_if(!$negocio, 404, 'El negocio no existe.');

        return response()->json([
            'folder' => $medios->carpeta($negocio),
            'configured' => $medios->configurado(),
            'public' => $medios->tienePublico(),
            'files' => $medios->listar($negocio),
        ]);
    }

    public function deleteBusinessMedia(Request $request, $id, BusinessMediaService $medios)
    {
        $negocio = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name', 'logo']);
        abort_if(!$negocio, 404, 'El negocio no existe.');

        $datos = $request->validate(['key' => 'required|string']);

        try {
            $medios->eliminar($negocio, $datos['key']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Si se borró justo el archivo que servía de logo, la referencia en la
        // tabla queda apuntando a nada: se limpia para que la app no muestre
        // una imagen rota.
        if ($negocio->logo && str_contains($negocio->logo, basename($datos['key']))) {
            DB::table('business')->where('busines_id', $id)->update(['logo' => null]);
        }

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /* ==================================================================
       PRODUCTOS
       ================================================================== */
}
