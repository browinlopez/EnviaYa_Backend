<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * API de superadministración.
 *
 * Los controladores de App\Http\Controllers\Admin\* que ya existían sirven al
 * panel Blade: devuelven vistas y viven en routes/web.php detrás de sesión.
 * Un SPA necesita JSON y token Sanctum, así que esta clase expone la misma
 * información en el formato que consume el panel de React.
 *
 * Se usa el query builder en vez de Eloquent a propósito: casi todo son
 * agregados con varios joins, y una consulta explícita evita tanto el
 * problema N+1 como depender de relaciones que el resto del proyecto define
 * de forma desigual.
 *
 * Convención de estados de `orderssales.state`:
 *   1 en preparación · 2 listo para recoger · 3 en camino · 4 entregado
 */
class AdminApiController extends Controller
{
    private const ENTREGADO = 4;
    private const ACTIVOS = [1, 2, 3];

    /** Estados de `payments.status` que significan "la plata entró". */
    private const PAGO_OK = ['approved', 'paid'];

    /**
     * Subconsulta: ¿esta orden tiene al menos un pago aprobado?
     *
     * Se correlaciona con el alias `o` de la consulta externa, así que quien
     * la use debe nombrar así la tabla `orderssales`.
     */
    private function pagoAprobado($q)
    {
        return $q->select(DB::raw(1))
            ->from('payments as pg')
            ->whereColumn('pg.orderSales_id', 'o.orderSales_id')
            ->whereIn('pg.status', self::PAGO_OK);
    }

    /* ==================================================================
       PANEL
       ================================================================== */

    public function overview(Request $request)
    {
        $dias = $this->rango($request);
        $desde = Carbon::now()->subDays($dias - 1)->startOfDay();
        $desdePrevio = (clone $desde)->subDays($dias);

        // Un solo recorrido de la tabla para los dos periodos: pedir dos
        // veces lo mismo con distinto WHERE duplica el escaneo sin ganar
        // nada.
        $ordenes = DB::table('orderssales')
            ->where('sale_date', '>=', $desdePrevio)
            ->get(['orderSales_id', 'state', 'total', 'subtotal', 'domicilio', 'domiciliary_fee', 'sale_date', 'payment_state']);

        $enPeriodo = fn($o) => Carbon::parse($o->sale_date)->gte($desde);
        $entregadas = $ordenes->where('state', self::ENTREGADO);

        $actual = $entregadas->filter($enPeriodo);
        $previo = $entregadas->reject($enPeriodo);

        $totales = [
            'revenue'          => round($actual->sum('total'), 2),
            'revenue_prev'     => round($previo->sum('total'), 2),
            'orders'           => $ordenes->filter($enPeriodo)->count(),
            'orders_prev'      => $ordenes->reject($enPeriodo)->count(),
            'avg_ticket'       => $actual->count() ? round($actual->sum('total') / $actual->count(), 2) : 0,
            'delivery_fees'    => round($actual->sum('domicilio'), 2),
            'courier_earnings' => round($actual->sum('domiciliary_fee'), 2),
        ];

        // Conteos globales: no dependen del rango, describen el estado actual.
        $totales += [
            'users'          => DB::table('user')->count(),
            'businesses'     => DB::table('business')->count(),
            'products'       => DB::table('products')->count(),
            'active_orders'  => DB::table('orderssales')->whereIn('state', self::ACTIVOS)->count(),
            // "Pendiente de pago" se resuelve contra la tabla `payments`, que
            // es el registro real del dinero recibido, y no contra
            // `orderssales.payment_state`, que es una copia denormalizada que
            // puede quedar desincronizada. Usar la bandera hacía que el panel
            // contara como pendientes pedidos que la pantalla de Pagos
            // mostraba aprobados: dos pantallas, dos respuestas.
            'pending_payment' => DB::table('orderssales as o')
                ->whereIn('o.state', self::ACTIVOS)
                ->whereNotExists(fn($q) => $this->pagoAprobado($q))
                ->count(),
            // Pedidos que llevan más de un día sin llegar a entregado: la
            // señal más barata de que algo se atascó.
            'stalled_orders' => DB::table('orderssales')
                ->whereIn('state', self::ACTIVOS)
                ->where('sale_date', '<', Carbon::now()->subDay())
                ->count(),
        ];

        $repartidores = $this->resumenDomiciliarios();
        $totales['couriers_total'] = $repartidores->count();
        $totales['couriers_available'] = $repartidores->where('available', 1)->count();
        $totales['couriers_at_limit'] = $repartidores
            ->where('active_deliveries', '>=', (int) config('services.max_active_deliveries', 3))
            ->count();

        return response()->json([
            'range'           => $dias,
            'totals'          => $totales,
            'series'          => $this->serieDiaria($entregadas, $desde, $dias),
            'orders_by_state' => DB::table('orderssales')
                ->selectRaw('state, COUNT(*) as count')
                ->groupBy('state')
                ->orderBy('state')
                ->get(),
            'top_businesses'  => $this->topNegocios($desde),
            'couriers'        => $repartidores->sortByDesc('deliveries')->values(),
            'recent_orders'   => $this->ordenesBase()->orderByDesc('o.sale_date')->limit(10)->get(),
        ]);
    }

    /** Serie por día con el periodo anterior alineado al mismo índice. */
    private function serieDiaria($entregadas, Carbon $desde, int $dias): array
    {
        $porDia = $entregadas->groupBy(fn($o) => Carbon::parse($o->sale_date)->toDateString());
        $serie = [];

        for ($i = 0; $i < $dias; $i++) {
            $dia = (clone $desde)->addDays($i);
            $diaPrevio = (clone $dia)->subDays($dias);

            $serie[] = [
                'date'         => $dia->toDateString(),
                'label'        => $dia->format('d M'),
                'revenue'      => round(($porDia[$dia->toDateString()] ?? collect())->sum('total'), 2),
                'revenue_prev' => round(($porDia[$diaPrevio->toDateString()] ?? collect())->sum('total'), 2),
                'orders'       => ($porDia[$dia->toDateString()] ?? collect())->count(),
            ];
        }

        return $serie;
    }

    private function topNegocios(Carbon $desde)
    {
        return DB::table('business as b')
            ->leftJoin('orderssales as o', function ($j) use ($desde) {
                $j->on('o.busines_id', '=', 'b.busines_id')
                    ->where('o.state', '=', self::ENTREGADO)
                    ->where('o.sale_date', '>=', $desde);
            })
            ->groupBy('b.busines_id', 'b.name', 'b.logo', 'b.qualification')
            ->orderByDesc(DB::raw('COALESCE(SUM(o.total), 0)'))
            ->limit(8)
            ->get([
                'b.busines_id',
                'b.name',
                'b.logo',
                'b.qualification',
                DB::raw('COUNT(o.orderSales_id) as orders'),
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ]);
    }

    /* ==================================================================
       USUARIOS
       ================================================================== */

    public function users()
    {
        return response()->json(
            DB::table('user')
                ->orderBy('user_id')
                ->get([
                    'user_id', 'name', 'email', 'phone', 'address', 'rol',
                    'qualification', 'state', 'email_verified_at',
                ])
        );
    }

    /**
     * Crea un usuario de cualquier rol.
     *
     * El correo se marca como verificado en el acto: lo está dando de alta un
     * administrador, y dejarlo sin verificar lo bloquearía al iniciar sesión
     * (el login rechaza cuentas sin verificar).
     *
     * Según el rol se crea además su fila satélite: `buyer` para compradores,
     * `owner` para tenderos y `domiciliary` para repartidores. Sin ella la app
     * falla al cargar el perfil.
     */
    public function storeUser(Request $request)
    {
        $datos = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:user,email',
            'password' => 'required|string|min:6',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string|max:255',
            'rol'      => 'required|integer|exists:rol,rol_id',
            'document' => 'nullable|string|max:50',
        ]);

        $id = DB::transaction(function () use ($datos) {
            $userId = DB::table('user')->insertGetId([
                'name'              => $datos['name'],
                'email'             => $datos['email'],
                'password'          => Hash::make($datos['password']),
                'phone'             => $datos['phone'] ?? null,
                'address'           => $datos['address'] ?? null,
                'rol'               => $datos['rol'],
                'state'             => 1,
                'qualification'     => 0,
                'email_verified_at' => now(),
            ]);

            match ((int) $datos['rol']) {
                1 => DB::table('buyer')->insert([
                    'user_id' => $userId,
                    'qualification' => 0,
                    'belongs_to_complex' => 0,
                    'state' => 1,
                ]),
                2 => DB::table('owner')->insert([
                    'user_id' => $userId,
                    'document_number' => $datos['document'] ?? null,
                    'state' => 1,
                ]),
                3 => DB::table('domiciliary')->insert([
                    'user_id' => $userId,
                    'document' => $datos['document'] ?? null,
                    'available' => 0, // entra fuera de turno: lo activa él o el admin
                    'qualification' => 0,
                    'state' => 1,
                ]),
                default => null,
            };

            return $userId;
        });

        return response()->json(['message' => 'Usuario creado.', 'user_id' => $id], 201);
    }

    public function showUser($id)
    {
        $user = DB::table('user')->where('user_id', $id)->first([
            'user_id', 'name', 'email', 'phone', 'address', 'rol',
            'qualification', 'state', 'email_verified_at',
        ]);

        abort_if(!$user, 404, 'El usuario no existe.');

        return response()->json($user);
    }

    public function updateUser(Request $request, $id)
    {
        $existe = DB::table('user')->where('user_id', $id)->exists();
        abort_if(!$existe, 404, 'El usuario no existe.');

        $datos = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('user', 'email')->ignore($id, 'user_id')],
            'phone'    => 'sometimes|nullable|string|max:20',
            'address'  => 'sometimes|nullable|string|max:255',
            'rol'      => 'sometimes|integer|exists:rol,rol_id',
            'state'    => 'sometimes|boolean',
            'password' => 'sometimes|string|min:6',
        ]);

        // Un administrador no puede quitarse a sí mismo el rol: si se
        // equivoca queda sin acceso al panel y hay que arreglarlo a mano en
        // la base.
        if (isset($datos['rol']) && (int) $id === (int) $request->user()->user_id && (int) $datos['rol'] !== 4) {
            return response()->json([
                'message' => 'No puedes cambiar tu propio rol de administrador.',
            ], 422);
        }

        if (isset($datos['password'])) {
            $datos['password'] = Hash::make($datos['password']);
        }

        if ($datos) {
            DB::table('user')->where('user_id', $id)->update($datos);
        }

        return $this->showUser($id);
    }

    /* ==================================================================
       NEGOCIOS
       ================================================================== */

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
     * Resuelve la imagen principal de una colección desde `media_files`.
     *
     * El campo `logo`/`image` del registro solo se rellena cuando hay dominio
     * público configurado, porque una URL firmada caduca y no se puede
     * persistir. Para que el panel muestre la imagen igualmente, se resuelve
     * al vuelo desde la tabla de archivos.
     *
     * Se hace en UNA consulta para toda la colección: pedir la imagen fila a
     * fila sería el problema N+1 de manual.
     */
    private function conImagenPrincipal($filas, string $entidad, string $campoId, string $campoImagen, MediaService $medios)
    {
        $ids = $filas->pluck($campoId)->filter()->all();

        if (!$ids) {
            return $filas;
        }

        $principales = DB::table('media_files')
            ->where('entity_type', $entidad)
            ->whereIn('entity_id', $ids)
            ->where('is_primary', true)
            ->pluck('object_key', 'entity_id');

        return $filas->map(function ($fila) use ($principales, $campoId, $campoImagen, $medios) {
            $key = $principales[$fila->{$campoId}] ?? null;

            /*
             * El archivo marcado como principal MANDA sobre la columna.
             *
             * La columna suele arrastrar la URL con la que se sembró el
             * registro (una imagen de Google, por ejemplo). Si alguien sube un
             * logo desde el panel, ese es el logo actual: dejar ganar a la
             * columna haría que la subida no se viera y pareciera que falló.
             */
            if ($key) {
                $fila->{$campoImagen} = $medios->url($key);
            }

            return $fila;
        });
    }

    /* ==================================================================
       MEDIOS DE CUALQUIER ENTIDAD (Cloudflare R2)
       ================================================================== */

    /**
     * Resuelve el nombre de la entidad para construir la carpeta.
     * Devuelve null si el registro no existe.
     */
    private function nombreDeEntidad(string $entidad, $id): ?string
    {
        return match ($entidad) {
            'negocios'  => DB::table('business')->where('busines_id', $id)->value('name'),
            'productos' => DB::table('products')->where('products_id', $id)->value('name'),
            'conjuntos' => DB::table('residential_complexes')->where('complex_id', $id)->value('name'),
            'usuarios'  => DB::table('user')->where('user_id', $id)->value('name'),
            'categorias' => DB::table('category_business')->where('id', $id)->value('name'),
            default     => null,
        };
    }

    /**
     * Estado del almacenamiento, para la pantalla de Ajustes.
     *
     * Media plataforma depende de R2 (logos, galerías, documentos) y hasta
     * ahora la única forma de saber si estaba bien configurado era abrir la
     * ficha de un registro y tratar de subir algo.
     */
    public function storageStatus(MediaService $medios)
    {
        $porTipo = DB::table('media_files')
            ->groupBy('entity_type')
            ->orderBy('entity_type')
            ->get([
                'entity_type',
                DB::raw('COUNT(*) as files'),
                DB::raw('COALESCE(SUM(size_bytes), 0) as bytes'),
            ]);

        $disco = config('filesystems.disks.r2');

        return response()->json([
            'configured' => $medios->configurado(),
            'public'     => $medios->tienePublico(),
            'bucket'     => $disco['bucket'] ?? null,
            // El endpoint lleva la cuenta de Cloudflare en el subdominio; se
            // manda solo el host para no exponer más de lo necesario.
            'endpoint'   => $disco['endpoint'] ? parse_url($disco['endpoint'], PHP_URL_HOST) : null,
            'public_url' => $disco['url'] ?? null,
            'files'      => (int) $porTipo->sum('files'),
            'bytes'      => (int) $porTipo->sum('bytes'),
            'by_entity'  => $porTipo,
        ]);
    }

    public function media(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id);
        abort_if($nombre === null, 404, 'El registro no existe.');

        return response()->json([
            'folder' => $medios->carpeta($entidad, $id, $nombre),
            'configured' => $medios->configurado(),
            'public' => $medios->tienePublico(),
            // El panel oculta las pestañas cuando la entidad admite una sola
            // imagen, en vez de ofrecer opciones que el servidor ignora.
            'multiple' => $medios->admiteVarios($entidad),
            'documents_only' => $medios->soloDocumentos($entidad),
            'files' => $medios->listar($entidad, $id),
        ]);
    }

    /**
     * Sube un archivo a la carpeta de la entidad.
     *
     * Pasa por el backend y nunca desde el navegador: las llaves de R2 dan
     * permiso sobre todo el bucket y no pueden salir del servidor.
     */
    public function uploadMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id);
        abort_if($nombre === null, 404, 'El registro no existe.');

        $request->validate([
            'file' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,gif,pdf',
            'tipo' => 'sometimes|string|in:logo,galeria,documentos',
        ]);

        $tipo = $request->input('tipo', 'galeria');

        try {
            // El servicio registra el archivo en `media_files` y, si el logo
            // cambia, sincroniza la columna que consume la app. Acá ya no se
            // toca la base: guardar la URL firmada en `business.logo` es lo
            // que rompía la subida (600 caracteres en un varchar(255)).
            $subido = $medios->subir(
                $entidad,
                $id,
                $nombre,
                $request->file('file'),
                $tipo,
                $request->user()->user_id ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Archivo cargado.',
            'file' => $subido,
            'folder' => $medios->carpeta($entidad, $id, $nombre),
            'public' => $medios->tienePublico(),
        ], 201);
    }

    public function deleteMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        $nombre = $this->nombreDeEntidad($entidad, $id);
        abort_if($nombre === null, 404, 'El registro no existe.');

        $datos = $request->validate(['id' => 'required|integer']);

        try {
            // El servicio comprueba la pertenencia contra la base y, si era la
            // imagen principal, promueve otra y sincroniza la columna.
            $medios->eliminar($entidad, $id, (int) $datos['id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /** Marca cuál de las imágenes es la principal de la entidad. */
    public function setPrimaryMedia(Request $request, string $entidad, $id, MediaService $medios)
    {
        abort_if($this->nombreDeEntidad($entidad, $id) === null, 404, 'El registro no existe.');

        $datos = $request->validate(['id' => 'required|integer']);

        try {
            $medios->marcarPrincipal($entidad, $id, (int) $datos['id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Imagen principal actualizada.']);
    }

    /* ==================================================================
       MEDIOS DEL NEGOCIO (Cloudflare R2)
       ================================================================== */

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

    public function products(MediaService $medios)
    {
        // El producto vive en `products` y su precio/existencias en
        // `products_business`: la misma referencia puede aparecer varias
        // veces, una por tienda, y cada fila es una oferta distinta.
        // Las unidades vendidas van por subconsulta: unir el detalle de
        // pedidos aquí duplicaría cada renglón una vez por tienda que ofrece
        // el mismo producto.
        $filas = DB::table('products as p')
                ->leftJoin('products_business as pb', 'pb.products_id', '=', 'p.products_id')
                ->leftJoin('business as b', 'b.busines_id', '=', 'pb.busines_id')
                ->leftJoin('category as c', 'c.category_id', '=', 'p.category_id')
                ->orderBy('p.name')
                ->select([
                    'p.products_id', 'p.name', 'p.description', 'p.image', 'p.state',
                    'pb.busines_products_id', 'pb.price', 'pb.amount', 'pb.qualification',
                    'b.busines_id', 'b.name as business_name', 'c.name as category_name',
                ])
                ->selectSub(
                    DB::table('orderssales_detail')
                        ->selectRaw('COALESCE(SUM(amount), 0)')
                        ->whereColumn('orderssales_detail.product_id', 'p.products_id'),
                    'sold',
                )
                ->get();

        return response()->json(
            $this->conImagenPrincipal($filas, 'productos', 'products_id', 'image', $medios)
        );
    }

    /**
     * Alta de producto.
     *
     * El producto vive en `products` y su precio y existencias en
     * `products_business`: si se indica negocio, se crean las dos filas en la
     * misma transacción, porque un producto sin oferta no aparece en ninguna
     * tienda.
     */
    public function storeProduct(Request $request)
    {
        $datos = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'image'       => 'nullable|string',
            'category_id' => 'nullable|integer|exists:category,category_id',
            'busines_id'  => 'nullable|integer|exists:business,busines_id',
            'price'       => 'nullable|numeric|min:0',
            'amount'      => 'nullable|integer|min:0',
        ]);

        $id = DB::transaction(function () use ($datos) {
            $productoId = DB::table('products')->insertGetId([
                'name'        => $datos['name'],
                'description' => $datos['description'] ?? null,
                'image'       => $datos['image'] ?? null,
                'category_id' => $datos['category_id'] ?? null,
                'state'       => 1,
            ]);

            if (!empty($datos['busines_id'])) {
                DB::table('products_business')->insert([
                    'busines_id'    => $datos['busines_id'],
                    'products_id'   => $productoId,
                    'price'         => $datos['price'] ?? 0,
                    'amount'        => $datos['amount'] ?? 0,
                    'qualification' => 0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }

            return $productoId;
        });

        return response()->json(['message' => 'Producto creado.', 'products_id' => $id], 201);
    }

    public function showProduct($id)
    {
        $producto = DB::table('products')->where('products_id', $id)->first();
        abort_if(!$producto, 404, 'El producto no existe.');

        return response()->json($producto);
    }

    public function updateProduct(Request $request, $id)
    {
        abort_if(!DB::table('products')->where('products_id', $id)->exists(), 404, 'El producto no existe.');

        $datos = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'image'       => 'sometimes|nullable|string',
            'state'       => 'sometimes|boolean',
        ]);

        if ($datos) {
            DB::table('products')->where('products_id', $id)->update($datos);
        }

        return $this->showProduct($id);
    }

    /* ==================================================================
       ÓRDENES
       ================================================================== */

    /** Consulta base con los nombres ya resueltos, para lista y detalle. */
    private function ordenesBase()
    {
        return DB::table('orderssales as o')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'o.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            ->leftJoin('business as b', 'b.busines_id', '=', 'o.busines_id')
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'o.domiciliary_id')
            ->leftJoin('user as du', 'du.user_id', '=', 'd.user_id')
            ->leftJoin('user_address as ua', 'ua.address_id', '=', 'o.address_id')
            ->select([
                'o.orderSales_id', 'o.state', 'o.payment_state', 'o.total', 'o.subtotal',
                'o.domicilio', 'o.domiciliary_fee', 'o.sale_date', 'o.created_at',
                'o.delivery_date', 'o.dispatched_at', 'o.pickup', 'o.methods_id', 'o.forms_id',
                'bu.name as buyer_name', 'bu.phone as buyer_phone',
                'b.busines_id', 'b.name as business_name',
                'd.domiciliary_id', 'du.name as domiciliary_name',
                'ua.address as address',
            ])
            // Bandera derivada del registro de pagos. El panel muestra esto y
            // no `payment_state`, para que la lista de pedidos y la de pagos
            // no puedan contradecirse.
            ->selectRaw(
                'EXISTS (SELECT 1 FROM payments pg
                          WHERE pg.orderSales_id = o.orderSales_id
                            AND pg.status IN (?, ?)) as paid',
                self::PAGO_OK,
            );
    }

    /**
     * Pedidos, paginados en el servidor.
     *
     * Es el listado que crece sin techo: cada pedido que entra se queda para
     * siempre. Devolverlos todos funcionaba con nueve y se vuelve inusable con
     * cincuenta mil, así que la búsqueda, el orden y el corte se hacen contra
     * la base y no en el navegador.
     */
    public function orders(Request $request)
    {
        return response()->json(ListadoPaginado::responder(
            $request,
            $this->ordenesBase(),
            buscables: ['bu.name', 'b.name', 'du.name', 'o.orderSales_id'],
            ordenables: [
                'orderSales_id' => 'o.orderSales_id',
                'sale_date'     => 'o.sale_date',
                'total'         => 'o.total',
                'state'         => 'o.state',
                'business_name' => 'b.name',
                'buyer_name'    => 'bu.name',
            ],
            ordenPorDefecto: 'sale_date',
        ));
    }

    public function showOrder($id)
    {
        $orden = $this->ordenesBase()->where('o.orderSales_id', $id)->first();
        abort_if(!$orden, 404, 'El pedido no existe.');

        $orden->items = DB::table('orderssales_detail as od')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->where('od.orderSales_id', $id)
            ->get(['od.orderDet_id', 'od.product_id', 'od.amount', 'od.unit_price', 'p.name']);

        return response()->json($orden);
    }

    /* ==================================================================
       DOMICILIARIOS
       ================================================================== */

    private function resumenDomiciliarios()
    {
        // Mismo criterio que en `businesses()`: agregados por subconsulta.
        // Unir a la vez `orderssales` y `business_domiciliary` multiplicaba
        // las ganancias por la cantidad de negocios asignados al repartidor.
        $deOrdenes = fn(callable $extra) => $extra(
            DB::table('orderssales')->whereColumn('orderssales.domiciliary_id', 'd.domiciliary_id'),
        );

        return DB::table('domiciliary as d')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderBy('u.name')
            ->select([
                'd.domiciliary_id', 'd.user_id', 'd.available', 'd.document',
                'd.qualification', 'd.state',
                'd.contract_media_id', 'd.contract_signed_at', 'd.contract_city',
                'u.name', 'u.email', 'u.phone',
                // `available` es el turno (hoy salgo / hoy no). `user.state`
                // es el acceso a la app. Son cosas distintas y la interfaz
                // tiene que poder mostrarlas por separado.
                'u.state as account_active',
            ])
            ->selectSub(
                $deOrdenes(fn($q) => $q->selectRaw('COUNT(*)')->where('state', self::ENTREGADO)),
                'deliveries',
            )
            ->selectSub(
                $deOrdenes(fn($q) => $q->selectRaw('COUNT(*)')->whereIn('state', self::ACTIVOS)),
                'active_deliveries',
            )
            ->selectSub(
                $deOrdenes(
                    fn($q) => $q->selectRaw('COALESCE(SUM(domiciliary_fee), 0)')
                        ->where('state', self::ENTREGADO),
                ),
                'earnings',
            )
            ->selectSub(
                DB::table('business_domiciliary')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('business_domiciliary.domiciliary_id', 'd.domiciliary_id'),
                'businesses_count',
            )
            // Los nombres, no solo cuántos: en la lista "1" no dice para quién
            // trabaja, que es lo que se quiere saber de un vistazo.
            ->selectSub(
                DB::table('business_domiciliary as bd2')
                    ->join('business as b2', 'b2.busines_id', '=', 'bd2.busines_id')
                    /*
                     * `ORDER BY ... SEPARATOR` dentro de GROUP_CONCAT es de
                     * MySQL. En SQLite —donde corren las pruebas— es un error
                     * de sintaxis que hacía fallar el endpoint entero con un
                     * 500, así que esta pantalla no se podía probar.
                     *
                     * El separador se deja igual en ambos ('|') porque el panel
                     * parte por él; lo que se pierde en SQLite es el orden
                     * alfabético, que solo afecta a cómo se lee la lista y no a
                     * lo que se está probando.
                     */
                    ->selectRaw(
                        in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
                            ? "GROUP_CONCAT(b2.name ORDER BY b2.name SEPARATOR '|')"
                            : "GROUP_CONCAT(b2.name, '|')"
                    )
                    ->whereColumn('bd2.domiciliary_id', 'd.domiciliary_id'),
                'businesses_names',
            )
            ->get();
    }

    public function domiciliaries()
    {
        return response()->json($this->resumenDomiciliarios());
    }

    /**
     * Alta de un domiciliario.
     *
     * Un repartidor son DOS filas: la cuenta en `user` (rol 3) y su ficha en
     * `domiciliary`. Se admite crear la cuenta desde cero o vincular una que
     * ya exista, porque a veces la persona ya está registrada como comprador.
     */
    public function storeDomiciliary(Request $request)
    {
        $datos = $request->validate([
            'user_id'  => 'nullable|integer|exists:user,user_id',
            'name'     => 'required_without:user_id|string|max:255',
            'email'    => 'required_without:user_id|email|max:255|unique:user,email',
            'password' => 'required_without:user_id|string|min:6',
            'phone'    => 'nullable|string|max:20',
            'document' => 'nullable|string|max:50',
            'available' => 'sometimes|boolean',
        ]);

        $id = DB::transaction(function () use ($datos) {
            $userId = $datos['user_id'] ?? null;

            if ($userId) {
                if (DB::table('domiciliary')->where('user_id', $userId)->exists()) {
                    abort(422, 'Ese usuario ya está registrado como domiciliario.');
                }
                // Pasa a rol domiciliario: si sigue como comprador, la app lo
                // manda a la pantalla equivocada al iniciar sesión.
                DB::table('user')->where('user_id', $userId)->update(['rol' => 3]);
            } else {
                $userId = DB::table('user')->insertGetId([
                    'name'              => $datos['name'],
                    'email'             => $datos['email'],
                    'password'          => Hash::make($datos['password']),
                    'phone'             => $datos['phone'] ?? null,
                    'rol'               => 3,
                    'state'             => 1,
                    'qualification'     => 0,
                    'email_verified_at' => now(),
                ]);
            }

            return DB::table('domiciliary')->insertGetId([
                'user_id'       => $userId,
                'document'      => $datos['document'] ?? null,
                'available'     => $datos['available'] ?? 0,
                'qualification' => 0,
                'state'         => 1,
            ]);
        });

        return response()->json(['message' => 'Domiciliario creado.', 'domiciliary_id' => $id], 201);
    }

    /**
     * Ficha completa de un domiciliario.
     *
     * Devuelve todo lo que hace falta para juzgarlo de un vistazo: qué lleva
     * encima ahora, qué entregó últimamente, qué dicen de él y para qué
     * negocios trabaja. El panel lo abre en un solo modal, así que traerlo en
     * una sola respuesta evita cuatro peticiones en cascada.
     */
    public function showDomiciliary($id)
    {
        $d = $this->resumenDomiciliarios()->firstWhere('domiciliary_id', (int) $id);
        abort_if(!$d, 404, 'El domiciliario no existe.');

        $pedidos = fn() => $this->ordenesBase()->where('o.domiciliary_id', $id);

        $d->active_orders = $pedidos()
            ->whereIn('o.state', self::ACTIVOS)
            ->orderBy('o.sale_date')
            ->get();

        $d->recent_deliveries = $pedidos()
            ->where('o.state', self::ENTREGADO)
            ->orderByDesc('o.delivery_date')
            ->limit(10)
            ->get();

        $d->reviews = DB::table('domiciliary_reviews as r')
            ->leftJoin('buyer as b', 'b.buyer_id', '=', 'r.buyer_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'b.user_id')
            ->where('r.domiciliary_id', $id)
            ->orderByDesc('r.reviews_id')
            ->limit(10)
            ->get([
                'r.reviews_id', 'r.qualification', 'r.comment', 'r.created_at',
                'u.name as author_name',
            ]);

        $d->businesses = DB::table('business_domiciliary as bd')
            ->leftJoin('business as b', 'b.busines_id', '=', 'bd.busines_id')
            ->where('bd.domiciliary_id', $id)
            ->get(['b.busines_id', 'b.name', 'b.logo', 'b.address']);

        // Promedio de minutos en ruta. Solo cuentan los pedidos con las dos
        // marcas: estimar los que faltan inventaría un dato.
        $d->avg_minutes = DB::table('orderssales')
            ->where('domiciliary_id', $id)
            ->where('state', self::ENTREGADO)
            ->whereNotNull('dispatched_at')
            ->whereNotNull('delivery_date')
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, dispatched_at, delivery_date)'));

        $d->contract = $this->contratoDe($d);

        return response()->json($d);
    }

    /* ==================================================================
       ACUERDO DE VINCULACIÓN
       ================================================================== */

    /** Datos del contrato archivado, o null si todavía no ha firmado. */
    private function contratoDe(object $d): ?array
    {
        if (!$d->contract_media_id) {
            return null;
        }

        $archivo = DB::table('media_files')->where('id', $d->contract_media_id)->first();

        // El archivo pudo borrarse desde la sección de documentos: entonces la
        // ficha debe decir que no hay contrato, no enlazar a un objeto muerto.
        if (!$archivo) {
            return null;
        }

        return [
            'media_id'  => (int) $archivo->id,
            'name'      => $archivo->original_name,
            'size'      => (int) $archivo->size_bytes,
            'url'       => app(MediaService::class)->url($archivo->object_key),
            'signed_at' => $d->contract_signed_at,
            'city'      => $d->contract_city,
        ];
    }

    /**
     * Genera el acuerdo de vinculación con la firma trazada en el panel.
     *
     * Se rehace desde cero cada vez: firmar de nuevo produce un documento
     * nuevo con su fecha, y el anterior se conserva en la carpeta de la
     * persona porque un acuerdo firmado no se sobrescribe.
     */
    public function signContract(Request $request, $id, ContratoService $contratos)
    {
        $datos = $request->validate([
            'signature' => 'required|string',
            'city'      => 'required|string|max:120',
            // Se puede corregir la cédula en el mismo paso: el acuerdo la
            // lleva impresa y firmarlo sin ella no tiene sentido.
            'document'  => 'sometimes|nullable|string|max:50',
        ]);

        $d = DB::table('domiciliary as d')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('d.domiciliary_id', $id)
            ->first(['d.domiciliary_id', 'd.user_id', 'd.document', 'u.name']);

        abort_if(!$d, 404, 'El domiciliario no existe.');

        // El documento corregido se usa para el acuerdo, pero NO se guarda
        // todavía: si la firma resulta inválida, la ficha no puede quedarse
        // con una cédula que nadie llegó a confirmar.
        $documentoNuevo = $datos['document'] ?? null;
        $d->document = $documentoNuevo ?: $d->document;

        if (!$d->document) {
            return response()->json([
                'message' => 'Registra el documento de identidad antes de firmar: el acuerdo lo lleva impreso.',
            ], 422);
        }

        try {
            $resultado = $contratos->generar(
                $d,
                $datos['signature'],
                $datos['city'],
                $request->user()->user_id ?? null,
                $request->user()->name ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($documentoNuevo) {
            DB::table('domiciliary')->where('domiciliary_id', $id)
                ->update(['document' => $documentoNuevo]);
        }

        return response()->json([
            'message'  => 'Acuerdo de vinculación firmado y archivado.',
            'contract' => $resultado,
        ], 201);
    }

    public function updateDomiciliary(Request $request, $id)
    {
        $domiciliario = DB::table('domiciliary')->where('domiciliary_id', $id)->first();
        abort_if(!$domiciliario, 404, 'El domiciliario no existe.');

        $datos = $request->validate([
            'available' => 'sometimes|boolean',
            'document'  => 'sometimes|nullable|string|max:50',
            'state'     => 'sometimes|boolean',
            // Bloqueo de la CUENTA: impide entrar a la app. No es lo mismo
            // que `available`, que solo dice si hoy acepta pedidos.
            'blocked'   => 'sometimes|boolean',
        ]);

        if (array_key_exists('blocked', $datos)) {
            $bloquear = (bool) $datos['blocked'];
            unset($datos['blocked']);

            $huerfanos = DB::table('orderssales')
                ->where('domiciliary_id', $id)
                ->whereIn('state', self::ACTIVOS)
                ->count();

            DB::transaction(function () use ($bloquear, $domiciliario, $id) {
                DB::table('user')
                    ->where('user_id', $domiciliario->user_id)
                    ->update(['state' => $bloquear ? 0 : 1]);

                if ($bloquear) {
                    // Sin revocar los tokens, quien ya tenía la sesión abierta
                    // sigue operando: el bloqueo solo aplicaría al próximo
                    // inicio de sesión, que puede no llegar nunca.
                    DB::table('personal_access_tokens')
                        ->where('tokenable_type', \App\Models\User::class)
                        ->where('tokenable_id', $domiciliario->user_id)
                        ->delete();

                    // Y se saca de la rueda de asignación, para que ningún
                    // tendero le despache mientras está bloqueado.
                    DB::table('domiciliary')->where('domiciliary_id', $id)->update(['available' => 0]);
                }
            });

            $respuesta = $this->showDomiciliary($id);

            // Bloquear a alguien que lleva pedidos encima los deja sin
            // repartidor: se avisa para que se reasignen, en vez de dejar que
            // se descubra cuando el cliente reclame.
            if ($bloquear && $huerfanos > 0) {
                $cuerpo = $respuesta->getData(true);
                $cuerpo['warning'] = "Quedaron {$huerfanos} pedido(s) en curso sin repartidor. Hay que reasignarlos.";
                $cuerpo['orphaned_orders'] = $huerfanos;
                return response()->json($cuerpo);
            }

            return $respuesta;
        }

        // Ponerlo como no disponible mientras carga pedidos dejaría entregas
        // huérfanas: el domiciliario ya no aparece para asignar, pero sigue
        // teniendo pedidos encima.
        if (array_key_exists('available', $datos) && !$datos['available']) {
            $activos = DB::table('orderssales')
                ->where('domiciliary_id', $id)
                ->whereIn('state', self::ACTIVOS)
                ->count();

            if ($activos > 0) {
                return response()->json([
                    'message' => "No se puede marcar como no disponible: tiene {$activos} entrega(s) en curso.",
                ], 422);
            }
        }

        if ($datos) {
            DB::table('domiciliary')->where('domiciliary_id', $id)->update($datos);
        }

        return $this->showDomiciliary($id);
    }

    /* ==================================================================
       PAGOS
       ================================================================== */

    /** Pagos, paginados en el servidor: crecen al mismo ritmo que los pedidos. */
    public function payments(Request $request)
    {
        $q = DB::table('payments as p')
            ->leftJoin('orderssales as o', 'o.orderSales_id', '=', 'p.orderSales_id')
            ->leftJoin('buyer as by', 'by.buyer_id', '=', 'o.buyer_id')
            ->leftJoin('user as bu', 'bu.user_id', '=', 'by.user_id')
            // El negocio del pedido: un pago suelto no dice a qué tienda
            // corresponde la venta, que es justo lo que hace falta para
            // conciliar y para pagarle a cada uno.
            ->leftJoin('business as b', 'b.busines_id', '=', 'o.busines_id')
            ->leftJoin('payment_methods as pm', 'pm.methods_id', '=', 'p.methods_id')
            ->select([
                'p.payments_id', 'p.orderSales_id', 'p.provider', 'p.provider_payment_id',
                'p.amount', 'p.subtotal', 'p.total', 'p.domicilio', 'p.domiciliary_fee',
                'p.payment_status', 'p.status', 'p.payment_date', 'p.created_at',
                'bu.name as buyer_name', 'pm.name as method_name',
                'b.busines_id', 'b.name as business_name', 'b.logo as business_logo',
                // Estado del pedido al que pertenece el cobro: un pago
                // aprobado sobre un pedido que todavía va en camino no es
                // lo mismo que uno ya entregado.
                'o.state as order_state', 'o.delivery_date',
            ]);

        return response()->json(ListadoPaginado::responder(
            $request,
            $q,
            buscables: ['bu.name', 'b.name', 'p.provider_payment_id', 'p.orderSales_id'],
            ordenables: [
                'payments_id'   => 'p.payments_id',
                'amount'        => 'p.amount',
                'total'         => 'p.total',
                'payment_date'  => 'p.payment_date',
                'business_name' => 'b.name',
            ],
            ordenPorDefecto: 'payments_id',
        ));
    }

    /* ==================================================================
       RESEÑAS
       ================================================================== */

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

        $filas = $q->orderByDesc('r.reviews_id')->get($cols);

        // `user_reviews` no tiene created_at en el esquema; se envía nulo en
        // vez de omitir la clave para que el panel no tenga que adivinar.
        if (!$m['has_dates']) {
            $filas = $filas->map(function ($f) {
                $f->created_at = null;
                return $f;
            });
        }

        return response()->json($filas);
    }

    public function deleteReview(Request $request)
    {
        $datos = $request->validate([
            'scope'      => 'required|in:business,domiciliary,user',
            'reviews_id' => 'required|integer',
        ]);

        $m = $this->mapaResenas($datos['scope']);

        $borradas = DB::table($m['table'])->where('reviews_id', $datos['reviews_id'])->delete();
        abort_if(!$borradas, 404, 'La reseña no existe.');

        return response()->json(['message' => 'Reseña eliminada.']);
    }

    /* ==================================================================
       CATEGORÍAS
       ================================================================== */

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

    public function complexes()
    {
        return response()->json(
            DB::table('residential_complexes as rc')
                ->leftJoin('buyer_complex as bc', 'bc.complex_id', '=', 'rc.complex_id')
                ->groupBy('rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                    'rc.latitude', 'rc.longitude', 'rc.municipality_id', 'm.name', 'dp.id', 'dp.name')
                ->orderBy('rc.name')
                ->leftJoin('municipalities as m', 'm.id', '=', 'rc.municipality_id')
                ->leftJoin('departments as dp', 'dp.id', '=', 'm.department_id')
                ->get([
                    'rc.complex_id', 'rc.name', 'rc.address', 'rc.state', 'rc.people_count',
                    'rc.latitude', 'rc.longitude', 'rc.municipality_id',
                    'm.name as municipality_name', 'dp.id as department_id', 'dp.name as department_name',
                    DB::raw('COUNT(bc.buyer_id) as residents_count'),
                ])
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
        ]);

        $id = DB::table('residential_complexes')->insertGetId([
            'name'            => $datos['name'],
            'address'         => $datos['address'] ?? null,
            'people_count'    => $datos['people_count'] ?? 0,
            'state'           => $datos['state'] ?? 1,
            'latitude'        => $datos['latitude'] ?? null,
            'longitude'       => $datos['longitude'] ?? null,
            'municipality_id' => $datos['municipality_id'] ?? null,
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
        ]);

        $n = DB::table('residential_complexes')->where('complex_id', $id)->update($datos);
        abort_if(!$n && !DB::table('residential_complexes')->where('complex_id', $id)->exists(), 404, 'El conjunto no existe.');

        return response()->json(['message' => 'Conjunto actualizado.']);
    }

    public function deleteComplex($id)
    {
        $vinculados = DB::table('buyer_complex')->where('complex_id', $id)->count();
        if ($vinculados) {
            return response()->json([
                'message' => "No se puede eliminar: {$vinculados} usuario(s) están vinculados a este conjunto.",
            ], 422);
        }

        $n = DB::table('residential_complexes')->where('complex_id', $id)->delete();
        abort_if(!$n, 404, 'El conjunto no existe.');

        return response()->json(['message' => 'Conjunto eliminado.']);
    }

    /* ==================================================================
       PROPIETARIOS
       ================================================================== */

    public function owners()
    {
        $propietarios = DB::table('owner as o')
            ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
            // `document_types` usa `id` como llave y guarda el rótulo en
            // `name_es` / `name_en`; no tiene columna `name`.
            ->leftJoin('document_types as dt', 'dt.id', '=', 'o.document_type_id')
            ->orderBy('u.name')
            ->get([
                'o.owner_id', 'o.user_id', 'o.document_type_id', 'o.document_number',
                'o.birthdate', 'o.contact_secondary', 'o.notes', 'o.state', 'o.profile_photo',
                'u.name', 'u.email', 'u.phone', 'u.address',
                'u.state as account_state',
                'dt.code as document_type',
            ]);

        // Los negocios se traen en una sola consulta y se reparten en
        // memoria: un join dejaría una fila por negocio y habría que
        // desduplicar el propietario.
        $negocios = DB::table('owner_busines as ob')
            ->leftJoin('business as b', 'b.busines_id', '=', 'ob.busines_id')
            ->leftJoin('orderssales as o', function ($j) {
                $j->on('o.busines_id', '=', 'b.busines_id')->where('o.state', '=', self::ENTREGADO);
            })
            ->groupBy('ob.owner_id', 'b.busines_id', 'b.name')
            ->get([
                'ob.owner_id', 'b.busines_id', 'b.name',
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ])
            ->groupBy('owner_id');

        return response()->json(
            $propietarios->map(function ($p) use ($negocios) {
                $suyos = $negocios[$p->owner_id] ?? collect();
                $p->businesses = $suyos->values();
                $p->businesses_count = $suyos->count();
                $p->business_ids = $suyos->pluck('busines_id')->filter()->values();
                $p->revenue = round($suyos->sum('revenue'), 2);
                return $p;
            })
        );
    }

    /** Tipos de documento y negocios libres, para los selectores de la ficha. */
    public function ownerOptions()
    {
        return response()->json([
            'document_types' => DB::table('document_types')
                ->orderBy('id')
                ->get(['id', 'code', DB::raw('name_es as name')]),
            'businesses' => DB::table('business as b')
                ->leftJoin('owner_busines as ob', 'ob.busines_id', '=', 'b.busines_id')
                ->leftJoin('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
                ->orderBy('b.name')
                ->get([
                    'b.busines_id', 'b.name', 'b.address', 'b.state',
                    'ob.owner_id as current_owner_id',
                    'u.name as current_owner_name',
                ]),
        ]);
    }

    /**
     * Crea un propietario: la cuenta de usuario (rol 2) y su ficha `owner`.
     *
     * Se hace en una transacción porque un usuario sin fila en `owner` puede
     * entrar a la app pero no administrar nada, y eso es peor que no existir.
     */
    public function storeOwner(Request $request)
    {
        $datos = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|max:255|unique:user,email',
            'password'          => 'required|string|min:6',
            'phone'             => 'nullable|string|max:20',
            'address'           => 'nullable|string|max:255',
            'document_type_id'  => 'nullable|integer|exists:document_types,id',
            'document_number'   => 'nullable|string|max:50',
            'birthdate'         => 'nullable|date',
            // La columna es varchar(45): validar con un tope mayor solo
            // cambiaría el 422 por un error de truncado en la base.
            'contact_secondary' => 'nullable|string|max:45',
            'notes'             => 'nullable|string',
            'state'             => 'sometimes|boolean',
            'business_ids'      => 'sometimes|array',
            'business_ids.*'    => 'integer|exists:business,busines_id',
        ]);

        $ownerId = DB::transaction(function () use ($datos) {
            $userId = DB::table('user')->insertGetId([
                'name'              => $datos['name'],
                'email'             => $datos['email'],
                'password'          => Hash::make($datos['password']),
                'phone'             => $datos['phone'] ?? null,
                'address'           => $datos['address'] ?? null,
                'rol'               => 2,
                'state'             => (int) ($datos['state'] ?? 1),
                'qualification'     => 0,
                // Lo da de alta un administrador: sin verificar, el login lo
                // rechazaría y la cuenta nacería inservible.
                'email_verified_at' => now(),
            ]);

            $ownerId = DB::table('owner')->insertGetId([
                'user_id'           => $userId,
                'document_type_id'  => $datos['document_type_id'] ?? null,
                'document_number'   => $datos['document_number'] ?? null,
                'birthdate'         => $datos['birthdate'] ?? null,
                'contact_secondary' => $datos['contact_secondary'] ?? null,
                'notes'             => $datos['notes'] ?? null,
                'state'             => (int) ($datos['state'] ?? 1),
            ]);

            $this->asignarNegocios($ownerId, $datos['business_ids'] ?? []);

            return $ownerId;
        });

        return response()->json(['message' => 'Propietario creado.', 'owner_id' => $ownerId], 201);
    }

    public function updateOwner(Request $request, $id)
    {
        $owner = DB::table('owner')->where('owner_id', $id)->first();
        abort_if(!$owner, 404, 'El propietario no existe.');

        $datos = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'email'             => ['sometimes', 'email', 'max:255', Rule::unique('user', 'email')->ignore($owner->user_id, 'user_id')],
            'password'          => 'sometimes|nullable|string|min:6',
            'phone'             => 'sometimes|nullable|string|max:20',
            'address'           => 'sometimes|nullable|string|max:255',
            'document_type_id'  => 'sometimes|nullable|integer|exists:document_types,id',
            'document_number'   => 'sometimes|nullable|string|max:50',
            'birthdate'         => 'sometimes|nullable|date',
            'contact_secondary' => 'sometimes|nullable|string|max:45',
            'notes'             => 'sometimes|nullable|string',
            'state'             => 'sometimes|boolean',
            'business_ids'      => 'sometimes|array',
            'business_ids.*'    => 'integer|exists:business,busines_id',
        ]);

        DB::transaction(function () use ($datos, $owner, $id) {
            $cuenta = array_filter([
                'name'    => $datos['name'] ?? null,
                'email'   => $datos['email'] ?? null,
                'phone'   => array_key_exists('phone', $datos) ? $datos['phone'] : null,
                'address' => array_key_exists('address', $datos) ? $datos['address'] : null,
            ], fn($v) => $v !== null);

            if (array_key_exists('state', $datos)) {
                $cuenta['state'] = (int) $datos['state'];
            }
            if (!empty($datos['password'])) {
                $cuenta['password'] = Hash::make($datos['password']);
            }
            if ($cuenta) {
                DB::table('user')->where('user_id', $owner->user_id)->update($cuenta);
            }

            $ficha = [];
            foreach (['document_type_id', 'document_number', 'birthdate', 'contact_secondary', 'notes'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $ficha[$campo] = $datos[$campo];
                }
            }
            if (array_key_exists('state', $datos)) {
                $ficha['state'] = (int) $datos['state'];
            }
            if ($ficha) {
                DB::table('owner')->where('owner_id', $id)->update($ficha);
            }

            if (array_key_exists('business_ids', $datos)) {
                $this->asignarNegocios((int) $id, $datos['business_ids']);
            }
        });

        return response()->json(['message' => 'Propietario actualizado.']);
    }

    /**
     * Reescribe qué negocios administra un propietario.
     *
     * Un negocio tiene un dueño y solo uno: al asignarlo se retira de quien lo
     * tuviera antes, o quedaría con dos administradores y ninguno de los dos
     * sabría por qué ve pedidos ajenos.
     */
    private function asignarNegocios(int $ownerId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::table('owner_busines')->where('owner_id', $ownerId)->delete();

        foreach ($ids as $negocio) {
            DB::table('owner_busines')->where('busines_id', $negocio)->delete();
            DB::table('owner_busines')->insert([
                'owner_id'   => $ownerId,
                'busines_id' => $negocio,
                'state'      => 1,
            ]);
        }
    }

    /* ==================================================================
       CONVERSACIONES
       ================================================================== */

    public function chats()
    {
        $chats = DB::table('chats as c')
            ->leftJoin('messages as m', 'm.chat_id', '=', 'c.chat_id')
            ->groupBy('c.chat_id', 'c.type', 'c.created_at')
            ->orderByDesc(DB::raw('MAX(m.created_at)'))
            ->get([
                'c.chat_id', 'c.type', 'c.created_at',
                DB::raw('COUNT(m.message_id) as messages_count'),
                DB::raw('MAX(m.created_at) as last_at'),
                DB::raw("SUM(CASE WHEN m.content LIKE '%\"type\":\"preorder\"%' THEN 1 ELSE 0 END) as preorders_count"),
            ]);

        $participantes = DB::table('chat_participants as cp')
            ->leftJoin('user as u', 'u.user_id', '=', 'cp.user_id')
            ->get(['cp.chat_id', 'u.user_id', 'u.name', 'u.rol'])
            ->groupBy('chat_id');

        // El último mensaje de cada chat, sin N+1: se trae el máximo id por
        // chat y luego esos mensajes en una sola consulta.
        $ultimosIds = DB::table('messages')
            ->selectRaw('MAX(message_id) as id')
            ->groupBy('chat_id')
            ->pluck('id');

        $ultimos = DB::table('messages')
            ->whereIn('message_id', $ultimosIds)
            ->get(['chat_id', 'content'])
            ->keyBy('chat_id');

        return response()->json(
            $chats->map(function ($c) use ($participantes, $ultimos) {
                $c->participants = ($participantes[$c->chat_id] ?? collect())->values();
                $contenido = $ultimos[$c->chat_id]->content ?? null;
                // Una pre-orden es JSON: mostrarlo crudo en la lista no dice
                // nada, así que se resume.
                $c->last_message = $contenido && str_starts_with(trim($contenido), '{')
                    ? '[Pre-orden]'
                    : $contenido;
                return $c;
            })
        );
    }

    public function chatMessages($chatId)
    {
        abort_if(!DB::table('chats')->where('chat_id', $chatId)->exists(), 404, 'La conversación no existe.');

        return response()->json(
            DB::table('messages as m')
                ->leftJoin('user as u', 'u.user_id', '=', 'm.user_id')
                ->where('m.chat_id', $chatId)
                ->orderBy('m.message_id')
                ->get(['m.message_id', 'm.chat_id', 'm.user_id', 'm.role_id', 'm.content', 'm.created_at', 'u.name as user_name'])
        );
    }

    /* ==================================================================
       AUDITORÍA
       ================================================================== */

    public function audits(Request $request)
    {
        $limite = min((int) $request->query('limit', 500), 2000);

        return response()->json(
            DB::table('audits as a')
                ->leftJoin('user as u', 'u.user_id', '=', 'a.user_id')
                ->orderByDesc('a.id')
                ->limit($limite)
                ->get([
                    'a.id', 'a.event', 'a.auditable_type', 'a.auditable_id',
                    'a.old_values', 'a.new_values', 'a.url', 'a.ip_address', 'a.created_at',
                    'u.name as user_name', 'u.email as user_email',
                ])
        );
    }

    /* ==================================================================
       REPORTES
       ================================================================== */

    public function report(Request $request, string $kind)
    {
        $dias = $this->rango($request);
        $desde = Carbon::now()->subDays($dias - 1)->startOfDay();

        return match ($kind) {
            'financial'   => response()->json($this->reporteFinanciero($desde, $dias)),
            'commercial'  => response()->json($this->reporteComercial($desde)),
            'operational' => response()->json($this->reporteOperacional($desde, $dias)),
            default       => response()->json(['message' => 'Tipo de reporte no válido.'], 404),
        };
    }

    private function reporteFinanciero(Carbon $desde, int $dias): array
    {
        $entregadas = DB::table('orderssales')
            ->where('state', self::ENTREGADO)
            ->where('sale_date', '>=', $desde)
            ->get(['total', 'subtotal', 'domicilio', 'domiciliary_fee', 'sale_date']);

        $porDia = $entregadas->groupBy(fn($o) => Carbon::parse($o->sale_date)->toDateString());
        $serie = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = (clone $desde)->addDays($i);
            $delDia = $porDia[$dia->toDateString()] ?? collect();
            $serie[] = [
                'date'     => $dia->toDateString(),
                'label'    => $dia->format('d M'),
                'revenue'  => round($delDia->sum('total'), 2),
                'delivery' => round($delDia->sum('domicilio'), 2),
            ];
        }

        $domicilios = $entregadas->sum('domicilio');
        $comisiones = $entregadas->sum('domiciliary_fee');

        return [
            'totals' => [
                'revenue'          => round($entregadas->sum('total'), 2),
                'subtotal'         => round($entregadas->sum('subtotal'), 2),
                'delivery_fees'    => round($domicilios, 2),
                'courier_earnings' => round($comisiones, 2),
                // Lo que queda para la plataforma y el negocio una vez
                // descontada la comisión del domiciliario.
                'business_net'     => round($entregadas->sum('subtotal') + ($domicilios - $comisiones), 2),
                'avg_ticket'       => $entregadas->count() ? round($entregadas->sum('total') / $entregadas->count(), 2) : 0,
            ],
            'series' => $serie,
        ];
    }

    private function reporteComercial(Carbon $desde): array
    {
        $productos = DB::table('orderssales_detail as od')
            ->join('orderssales as o', 'o.orderSales_id', '=', 'od.orderSales_id')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->where('o.state', self::ENTREGADO)
            ->where('o.sale_date', '>=', $desde)
            ->groupBy('p.products_id', 'p.name')
            ->orderByDesc(DB::raw('SUM(od.amount)'))
            ->get([
                'p.products_id', 'p.name',
                DB::raw('SUM(od.amount) as sold'),
                DB::raw('SUM(od.amount * od.unit_price) as revenue'),
            ]);

        $categorias = DB::table('orderssales_detail as od')
            ->join('orderssales as o', 'o.orderSales_id', '=', 'od.orderSales_id')
            ->leftJoin('products as p', 'p.products_id', '=', 'od.product_id')
            ->leftJoin('category as c', 'c.category_id', '=', 'p.category_id')
            ->where('o.state', self::ENTREGADO)
            ->where('o.sale_date', '>=', $desde)
            ->groupBy('c.category_id', 'c.name')
            ->orderByDesc(DB::raw('SUM(od.amount * od.unit_price)'))
            ->get([
                DB::raw("COALESCE(c.name, 'Sin categoría') as name"),
                DB::raw('SUM(od.amount) as units'),
                DB::raw('SUM(od.amount * od.unit_price) as revenue'),
            ]);

        $pedidos = DB::table('orderssales')
            ->where('state', self::ENTREGADO)
            ->where('sale_date', '>=', $desde)
            ->count();

        $unidades = $productos->sum('sold');

        return [
            'totals' => [
                'units'             => (int) $unidades,
                'distinct_products' => $productos->count(),
                'catalog_size'      => DB::table('products')->count(),
                'units_per_order'   => $pedidos ? round($unidades / $pedidos, 1) : 0,
            ],
            'top_products' => $productos->take(10)->values(),
            'by_category'  => $categorias,
        ];
    }

    private function reporteOperacional(Carbon $desde, int $dias): array
    {
        $ordenes = DB::table('orderssales')
            ->where('sale_date', '>=', $desde)
            ->get(['orderSales_id', 'state', 'sale_date', 'dispatched_at', 'delivery_date', 'domiciliary_id', 'domicilio', 'domiciliary_fee']);

        $entregadas = $ordenes->where('state', self::ENTREGADO);

        // Minutos entre el despacho y la entrega. Solo cuentan los pedidos
        // que tienen las dos marcas: estimar las que faltan inventaría datos.
        $minutos = $entregadas
            ->filter(fn($o) => $o->dispatched_at && $o->delivery_date)
            ->map(fn($o) => Carbon::parse($o->dispatched_at)->diffInMinutes(Carbon::parse($o->delivery_date)));

        $porDia = $ordenes->groupBy(fn($o) => Carbon::parse($o->sale_date)->toDateString());
        $serie = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = (clone $desde)->addDays($i);
            $delDia = $porDia[$dia->toDateString()] ?? collect();
            $serie[] = [
                'date'      => $dia->toDateString(),
                'label'     => $dia->format('d M'),
                'delivered' => $delDia->where('state', self::ENTREGADO)->count(),
                'cancelled' => $delDia->where('state', '!=', self::ENTREGADO)->count(),
            ];
        }

        $repartidores = DB::table('orderssales as o')
            ->join('domiciliary as d', 'd.domiciliary_id', '=', 'o.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('o.state', self::ENTREGADO)
            ->where('o.sale_date', '>=', $desde)
            ->groupBy('d.domiciliary_id', 'u.name')
            ->orderByDesc(DB::raw('COUNT(o.orderSales_id)'))
            ->get([
                'd.domiciliary_id', 'u.name',
                DB::raw('COUNT(o.orderSales_id) as delivered'),
                DB::raw('COALESCE(SUM(o.domicilio), 0) as delivery_fees'),
                DB::raw('COALESCE(SUM(o.domiciliary_fee), 0) as earnings'),
                DB::raw('AVG(CASE WHEN o.dispatched_at IS NOT NULL AND o.delivery_date IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, o.dispatched_at, o.delivery_date) END) as avg_minutes'),
            ]);

        $activos = $repartidores->count();

        return [
            'totals' => [
                'delivered'         => $entregadas->count(),
                'avg_minutes'       => $minutos->count() ? round($minutos->avg(), 1) : null,
                'fulfillment_rate'  => $ordenes->count() ? round(($entregadas->count() / $ordenes->count()) * 100, 1) : 0,
                'orders_per_courier' => $activos ? round($entregadas->count() / $activos, 1) : 0,
            ],
            'series'   => $serie,
            'couriers' => $repartidores,
        ];
    }

    /* ==================================================================
       AUXILIARES
       ================================================================== */

    /** Rango en días, acotado para que nadie pida un año de golpe por error. */
    private function rango(Request $request): int
    {
        $dias = (int) $request->query('range', 30);

        return max(1, min($dias, 365));
    }
}
