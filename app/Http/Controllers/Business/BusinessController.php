<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    // Listar todos los negocios con dueños y municipio
    public function index(Request $request)
    {
        $userId = $request->input('user_id');
        $affiliatedIds = [];
        $userAuthenticated = false;

        // Si se envía el user_id, validamos y cargamos afiliaciones
        if ($userId) {
            $validated = $request->validate([
                'user_id' => 'integer|exists:user,user_id',
            ]);

            $user = User::with('affiliatedBusinesses')->findOrFail($userId);
            $affiliatedIds = $user->affiliatedBusinesses->pluck('busines_id')->toArray();
            $userAuthenticated = true;
        }

        /*
         * Solo negocios activos.
         *
         * `state` existía en la tabla pero nadie lo consultaba: el panel
         * permitía marcar una tienda como inactiva y seguía apareciendo en la
         * app como si nada. Desactivar tiene que sacarla del catálogo o no
         * sirve de nada.
         */
        /*
         * Solo productos activos.
         *
         * `products.state` se devolvía pero no se filtraba en ningún sitio, así
         * que retirar un producto desde el panel lo dejaba a la venta en la
         * app. Se filtra al cargar la relación y no al mapear, para no traerlos
         * siquiera.
         */
        $businesses = Business::with([
            'owners', 'municipality', 'reviews',
            'products' => fn ($q) => $q->where('products.state', 1),
        ])
            ->where('state', 1)
            ->when(count($affiliatedIds) > 0, function ($q) use ($affiliatedIds) {
                // Ordena los negocios afiliados primero
                $q->orderByRaw("FIELD(busines_id," . implode(',', $affiliatedIds) . ") DESC");
            })
            ->orderBy('name')
            ->get();

        /*
         * Sin `$ligero`: acá no existe.
         *
         * La bandera `light` es de `indexByQualification`, y este `use` se
         * quedó con la variable de una copia. PHP no avisa de eso hasta que
         * ejecuta la closure, así que el método reventaba con 500 en cada
         * llamada: todo comprador con sesión iniciada abría el inicio y no veía
         * un solo negocio.
         */
        $formatted = $businesses->map(function ($business) use ($affiliatedIds, $userId) {
            $isAffiliated = in_array($business->busines_id, $affiliatedIds);

            return [
                'business_id'   => $business->busines_id,
                'name'          => $business->name,
                'phone'         => $business->phone,
                'address'       => $business->address,
                'qualification' => (float) $business->qualification,
                'razon_social'  => $business->razonSocial_DCD,
                'NIT'           => $business->NIT,
                'logo'          => $business->logo ?? 'https://example.com/default-logo.png',
                'state'         => (bool) $business->state,
                'type'          => $business->type,
                'municipality'  => $business->municipality ? [
                    'id'   => $business->municipality->id,
                    'name' => $business->municipality->name,
                ] : null,
                'owner_count'   => $business->owners->count(),
                /*
                 * DEL PROPIETARIO SOLO SALE LO QUE HACE FALTA PARA COMPRAR.
                 *
                 * Acá salían también su número de documento, su fecha de nacimiento, su
                 * teléfono secundario y las notas internas que le haya puesto el
                 * equipo. (Iba además un `document_type` que siempre valía null:
                 * la columna real es `document_type_id`.) Nada de eso lo usa la
                 * app —solo lee
                 * `user_id`, para abrir el chat— y `indexByQualification` es un
                 * endpoint PÚBLICO: cualquiera sin cuenta podía listar la cédula de
                 * todos los tenderos aliados.
                 *
                 * Si algún día el panel necesita esos campos, van por la API de
                 * administración, que ya exige rol 4 y módulo.
                 */
                'owners'        => $business->owners->map(function ($owner) {
                    return [
                        'owner_id'          => $owner->owner_id,
                        'user_id'           => $owner->user_id,
                        'profile_photo'     => $owner->profile_photo ?? 'https://example.com/default-user.png',
                        'state'             => (bool) $owner->state,
                    ];
                }),
                'products' => $business->products->map(function ($product) use ($isAffiliated, $userId) {
                    return [
                        'product_id'  => $product->products_id,
                        'name'        => $product->name,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'image'       => $product->image,
                        'state'       => (bool) $product->state,
                        // Si no hay user_id → precio 0
                        // Si hay user_id → mostrar precio solo si está afiliado
                        'price'       => $userId ? ($isAffiliated ? ($product->pivot->price ?? 0) : 0) : 0,
                    ];
                }),
                'reviews' => $business->reviews->map(function ($review) {
                    return [
                        'review_id'  => $review->reviews_id ?? null,
                        'buyer_id'   => $review->buyer_id,
                        'rating'     => (float) $review->qualification ?? 0,
                        'comment'    => $review->comment ?? '',
                        'created_at' => $review->created_at ?? null,
                    ];
                }),
                'is_affiliated' => $isAffiliated,
            ];
        });

        return response()->json([
            'user_authenticated' => $userAuthenticated,
            'businesses'         => $formatted
        ]);
    }

    public function indexByQualification(Request $request)
    {
        $userId = $request->input('user_id');
        $affiliatedIds = [];
        $userAuthenticated = false;

        // Si se envía user_id, validamos y cargamos afiliaciones
        if ($userId) {
            $validated = $request->validate([
                'user_id' => 'integer|exists:user,user_id',
            ]);

            $user = User::with('affiliatedBusinesses')->findOrFail($userId);
            $affiliatedIds = $user->affiliatedBusinesses->pluck('busines_id')->toArray();
            $userAuthenticated = true;
        }

        /*
         * MODO LIGERO
         *
         * Este listado llevaba dentro el CATÁLOGO ENTERO de cada negocio. Con la
         * base de demostración eso son 111 productos en un negocio, 24 KB de
         * JSON, y la respuesta completa pasaba de 96 KB para pintar unas
         * tarjetas que solo enseñan nombre, logo, nota y tipo.
         *
         * El coste no es teórico: en el emulador Android la respuesta se cortaba
         * a media descarga —`unexpected end of stream`— y la pantalla de inicio
         * se quedaba sin negocios. En un teléfono con datos móviles es medio
         * megabyte por cada vez que alguien abre la app.
         *
         * Va como bandera y no por defecto a propósito: las versiones de la app
         * que ya están instaladas leen `products` de acá para pintar la ficha
         * del negocio, y quitárselo las dejaría con el catálogo vacío. Piden el
         * modo ligero las versiones que saben pedir el detalle aparte.
         */
        $ligero = $request->boolean('light');

        $relaciones = ['owners', 'municipality'];

        if (!$ligero) {
            $relaciones = array_merge($relaciones, [
                'reviews', 'products.category',
                'products' => fn ($q) => $q->where('products.state', 1),
            ]);
        }

        // Ídem que en index(): un negocio desactivado no se publica.
        // Ver la nota de `index`: los productos retirados no salen del panel.
        $businesses = Business::with($relaciones)
            ->where('state', 1)
            ->orderByDesc('qualification')
            ->get();

        // Mapeamos los datos
        $formatted = $businesses->map(function ($business) use ($affiliatedIds, $userId, $ligero) {
            $isAffiliated = in_array($business->busines_id, $affiliatedIds);

            return [
                'business_id'   => $business->busines_id,
                'name'          => $business->name,
                'phone'         => $business->phone,
                'address'       => $business->address,
                'description'       => $business->description,
                'qualification' => (float) $business->qualification,
                'razon_social'  => $business->razonSocial_DCD,
                'NIT'           => $business->NIT,
                'logo'          => $business->logo ?? 'https://example.com/default-logo.png',
                'state'         => (bool) $business->state,
                'type'          => $business->type,
                'municipality'  => $business->municipality ? [
                    'id'   => $business->municipality->id,
                    'name' => $business->municipality->name,
                ] : null,
                'owner_count'   => $business->owners->count(),
                /*
                 * DEL PROPIETARIO SOLO SALE LO QUE HACE FALTA PARA COMPRAR.
                 *
                 * Acá salían también su número de documento, su fecha de nacimiento, su
                 * teléfono secundario y las notas internas que le haya puesto el
                 * equipo. (Iba además un `document_type` que siempre valía null:
                 * la columna real es `document_type_id`.) Nada de eso lo usa la
                 * app —solo lee
                 * `user_id`, para abrir el chat— y `indexByQualification` es un
                 * endpoint PÚBLICO: cualquiera sin cuenta podía listar la cédula de
                 * todos los tenderos aliados.
                 *
                 * Si algún día el panel necesita esos campos, van por la API de
                 * administración, que ya exige rol 4 y módulo.
                 */
                'owners'        => $business->owners->map(function ($owner) {
                    return [
                        'owner_id'          => $owner->owner_id,
                        'user_id'           => $owner->user_id,
                        'profile_photo'     => $owner->profile_photo ?? 'https://example.com/default-user.png',
                        'state'             => (bool) $owner->state,
                    ];
                }),
                // productos del negocio
                'products' => $ligero ? [] : $business->products->map(function ($product) use ($isAffiliated, $userId) {
                    return [
                        'product_id'  => $product->products_id,
                        'name'        => $product->name,
                        'description' => $product->description,
                        'category'    => $product->category ? $product->category->name : null,
                        'image'       => $product->image,
                        'state'       => (bool) $product->state,
                        // Si no hay user_id → precio 0
                        // Si hay user_id → mostrar precio solo si está afiliado
                        'price'       => $userId ? ($isAffiliated ? ($product->pivot->price ?? 0) : 0) : 0,
                    ];
                }),
                // reviews del negocio
                'reviews' => $ligero ? [] : $business->reviews->map(function ($review) {
                    return [
                        'review_id'  => $review->reviews_id ?? null,
                        'buyer_id'   => $review->buyer_id,
                        'rating'     => (float) $review->qualification ?? 0,
                        'comment'    => $review->comment ?? '',
                        'created_at' => $review->created_at ?? null,
                    ];
                }),
                'is_affiliated' => $isAffiliated,
            ];
        });

        return response()->json([
            'user_authenticated' => $userAuthenticated,
            'businesses'         => $formatted
        ]);
    }

    // Crear negocio con transacción
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
            'type' => 'required|integer',
        ]);

        DB::beginTransaction();

        try {
            $business = Business::create([
                'name' => $request->name,
                'phone' => $request->phone,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'municipality_id' => $request->municipality_id,
                'NIT' => $request->NIT,
                'razonSocial_DCD' => $request->razonSocial_DCD,
                'logo' => $request->logo,
                'type' => $request->type,
                'state' => true
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Negocio creado correctamente',
                'business' => $business->load('municipality')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Mostrar negocio con relaciones
    public function show(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
            'user_id'    => 'sometimes|nullable|integer|exists:user,user_id',
        ]);

        /*
         * EL PRECIO SOLO LO VE QUIEN ESTÁ AFILIADO.
         *
         * El listado ya aplicaba esta regla —sin afiliación los productos salen
         * en 0 y la app los pinta con un candado— y acá no: se devolvía
         * `$product->pivot->price` tal cual. Bastaba con llamar a este endpoint
         * con el identificador del negocio para leer todos sus precios sin estar
         * afiliado a él, saltándose lo que la otra ruta protege.
         *
         * Sin `user_id` se responde como a un invitado: todo en 0.
         */
        $userId = $request->input('user_id');

        $estaAfiliado = false;

        if ($userId) {
            $estaAfiliado = User::with('affiliatedBusinesses')
                ->findOrFail($userId)
                ->affiliatedBusinesses
                ->contains('busines_id', (int) $request->busines_id);
        }

        /*
         * Un negocio desactivado no se muestra ni entrando por su identificador.
         * El listado sí filtraba por `state`, pero acá se hacía un findOrFail
         * pelado: bastaba conservar el id —de un favorito, de un pedido viejo,
         * de un enlace— para seguir viendo la tienda y su catálogo como si nada.
         */
        $business = Business::with([
            'owners', 'reviews', 'municipality', 'products.category',
            'products' => fn ($q) => $q->where('products.state', 1),
        ])
            ->where('state', 1)
            ->findOrFail($request->busines_id);

        return response()->json([
            'business_id'   => $business->busines_id,
            'name'          => $business->name,
            'phone'         => $business->phone,
            'address'       => $business->address,
            'qualification' => (float) $business->qualification,
            'razon_social'  => $business->razonSocial_DCD,
            'NIT'           => $business->NIT,
            'logo'          => $business->logo ?? 'https://example.com/default-logo.png',
            'type'           => $business->type,
            'state'         => (bool) $business->state,
            'municipality'  => $business->municipality ? [
                'id'   => $business->municipality->id,
                'name' => $business->municipality->name,
            ] : null,
            'owner_count'   => $business->owners->count(),
            /*
             * DEL PROPIETARIO SOLO SALE LO QUE HACE FALTA PARA COMPRAR.
             *
             * Acá salían también su número de documento, su fecha de nacimiento, su
             * teléfono secundario y las notas internas que le haya puesto el
             * equipo. (Iba además un `document_type` que siempre valía null:
             * la columna real es `document_type_id`.) Nada de eso lo usa la
             * app —solo lee
             * `user_id`, para abrir el chat— y `indexByQualification` es un
             * endpoint PÚBLICO: cualquiera sin cuenta podía listar la cédula de
             * todos los tenderos aliados.
             *
             * Si algún día el panel necesita esos campos, van por la API de
             * administración, que ya exige rol 4 y módulo.
             */
            'owners'        => $business->owners->map(function ($owner) {
                return [
                    'owner_id'          => $owner->owner_id,
                    'user_id'           => $owner->user_id,
                    'profile_photo'     => $owner->profile_photo ?? 'https://example.com/default-user.png',
                    'state'             => (bool) $owner->state,
                ];
            }),
            'is_affiliated' => $estaAfiliado,
            /*
             * SIN PRECIO NO SE OFRECE.
             *
             * Copiar el surtido de otra tienda deja los productos en cero a
             * proposito: se copia QUE vende, no A CUANTO. Si esos cero
             * llegaran al comprador, la tienda estaria anunciando decenas de
             * productos que no puede despachar, y el que queda mal es el
             * domiciliario en la puerta.
             *
             * Se filtra por el precio GUARDADO y no por el que se muestra:
             * quien no esta afiliado sigue viendo el catalogo con candado,
             * como hasta ahora.
             */
            'products' => $business->products
                ->filter(fn ($p) => (float) ($p->pivot->price ?? 0) > 0)
                ->values()
                ->map(function ($product) use ($estaAfiliado) {
                return [
                    'product_id' => $product->products_id,
                    'name'       => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    // La ficha agrupa por nombre de categoría, igual que el
                    // listado; con solo el id no podía pintar las secciones.
                    'category'   => $product->category?->name,
                    'image'      => $product->image,
                    'state'      => (bool) $product->state,
                    'price'      => $estaAfiliado ? ($product->pivot->price ?? 0) : 0,
                ];
            }),
            'reviews' => $business->reviews->map(function ($review) {
                return [
                    'review_id'  => $review->reviews_id ?? null,
                    'buyer_id'   => $review->buyer_id,
                    'rating'     => (float) $review->qualification ?? 0,
                    'comment'    => $review->comment ?? '',
                    'created_at' => $review->created_at ?? null,
                ];
            }),
        ]);
    }

    // Actualizar negocio
    public function update(Request $request)
    {
        $request->validate([
            'busines_id' => 'required|integer|exists:business,busines_id',
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'NIT' => 'nullable|string',
            'razonSocial_DCD' => 'nullable|string',
            'logo' => 'nullable|string',
            'type' => 'nullable|integer|in:1,2',
            'state' => 'nullable|boolean',
            'owner_ids' => 'nullable|array',
            'owner_ids.*' => 'integer|exists:owner,owner_id'
        ]);

        DB::beginTransaction();

        try {
            $business = Business::with('owners')->findOrFail($request->busines_id);

            $business->update($request->only([
                'name',
                'phone',
                'address',
                'municipality_id',
                'NIT',
                'razonSocial_DCD',
                'logo',
                'type',
                'state'
            ]));

            if ($request->filled('owner_ids')) {
                $business->owners()->sync($request->owner_ids);
            }

            DB::commit();

            return response()->json([
                'message' => 'Negocio actualizado correctamente',
                'business' => $business->load('owners', 'products', 'reviews', 'municipality')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
