<?php

namespace App\Http\Controllers\Business;

use App\Services\NegocioParaLaApp;
use App\Services\TarifaPorDistancia;
use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsultaDeNegociosController extends Controller
{
    public function __construct(private readonly TarifaPorDistancia $distancias,
        private readonly NegocioParaLaApp $presentador)
    {
    }

    use ComprobarPertenencia;

    // Listar todos los negocios con dueños y municipio
    public function index(Request $request)
    {
        $userId = $request->input('user_id');
        $affiliatedIds = [];
        $userAuthenticated = false;

        /*
         * DÓNDE ESTÁ QUIEN PREGUNTA.
         *
         * Opcional a propósito: sin coordenadas la respuesta es la de siempre,
         * con todos los negocios y sin distancias. La app las manda cuando
         * tiene la ubicación o una dirección elegida.
         */
        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lon = $request->filled('lng') ? (float) $request->input('lng') : null;

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
         * EL MODO LIGERO, TAMBIEN ACA.
         *
         * Este es el listado que abre un comprador CON SESION, y viajaba
         * siempre con el catalogo entero de cada negocio: 105 KB medidos con la
         * base de demostracion —29 productos y sus reseñas por tienda— para
         * pintar seis tarjetas que solo enseñan nombre, categoria, nota y
         * domicilio. En un telefono con datos eso se paga cada vez que se abre
         * el inicio, y se vuelve a pagar al cambiar de direccion.
         *
         * Ademas es la unica peticion que fallaba de forma intermitente en el
         * emulador: la mas pesada, cortandose a medias. Es exactamente el
         * sintoma que ya describia `ListadoLigeroTest` para el otro listado.
         *
         * `indexByQualification` ya tenia la bandera; aca se habia quitado al
         * arreglar un 500 —la closure capturaba una variable que no existia— y
         * nadie la volvio a poner bien. Va OPCIONAL, como alli: las versiones
         * de la app ya instaladas siguen recibiendo el catalogo y no se quedan
         * con la ficha vacia.
         */
        $ligero = $request->boolean('light');

        $formatted = $businesses->map(function ($business) use ($affiliatedIds, $userId, $lat, $lon, $ligero) {
            $isAffiliated = in_array($business->busines_id, $affiliatedIds);

            /*
             * A cuánto está y cuánto costaría traer de acá.
             *
             * Se calcula por negocio porque cada tienda está en un sitio: la
             * misma persona paga $2.000 en la de la esquina y $4.000 en la de
             * tres kilómetros. Enseñarlo en la lista evita la sorpresa al
             * llegar al carrito.
             */
            $km = $this->distancias->kilometros(
                $business->latitude !== null ? (float) $business->latitude : null,
                $business->longitude !== null ? (float) $business->longitude : null,
                $lat,
                $lon,
            );

            return [
                'business_id'   => $business->busines_id,
                // Para pintarlos en el mapa cuando no hay ninguno cerca.
                'latitude'      => $business->latitude !== null ? (float) $business->latitude : null,
                'longitude'     => $business->longitude !== null ? (float) $business->longitude : null,
                'distance_km'   => $km === null ? null : round($km, 2),
                'delivery_fee'  => $this->distancias->paraDistancia($km),
                'in_range'      => $this->distancias->reparteHasta($km),
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
                'owners'        => $this->presentador->propietarios($business),
                /*
                 * Las claves siguen viajando, vacias: la app las lee sin
                 * comprobar si existen, y quitarlas del todo la obligaria a
                 * defenderse de un `undefined` en cada pantalla.
                 */
                'products' => $ligero
                    ? []
                    : $this->presentador->productos($business, $isAffiliated, $userId),
                'reviews' => $ligero ? [] : $this->presentador->resenas($business),
                'is_affiliated' => $isAffiliated,
            ];
        });

        /*
         * Los más cerca primero, y NUNCA se esconde ninguno.
         *
         * Quien vive donde todavía no hay cobertura tiene que poder ver qué
         * tiendas existen y dónde están —para saber si le sirve alguna, o
         * simplemente para ubicarlas—. Filtrarlas dejaría una pantalla vacía
         * sin explicación, que es la peor forma de decir "no llegamos a tu
         * zona". Se ordenan y se marca cuál queda fuera; decidir qué hacer con
         * eso es de la pantalla.
         */
        if ($lat !== null && $lon !== null) {
            $formatted = $formatted
                ->sortBy(fn ($n) => $n['distance_km'] ?? INF)
                ->values();
        }

        return response()->json([
            'user_authenticated' => $userAuthenticated,
            // Si es false, la app dice "no hay tiendas que repartan a tu zona"
            // y enseña igual la lista, ordenada de la más cercana en adelante.
            'hay_cercanos'       => $formatted->contains(fn ($n) => $n['in_range'] ?? false),
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

        /*
         * DESDE DÓNDE MIRA QUIEN PREGUNTA.
         *
         * `index` ya lo hacía y este método no, y ES ESTE el que pinta la
         * pantalla de inicio: las tarjetas enseñaban la tarifa plana del panel
         * mientras el pedido se creaba con la de la distancia. A 12,5 km eso
         * era prometer $2.000 y cobrar $14.000.
         *
         * Opcional: sin coordenadas responde como siempre.
         */
        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lon = $request->filled('lng') ? (float) $request->input('lng') : null;

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
        $formatted = $businesses->map(function ($business) use ($affiliatedIds, $userId, $ligero, $lat, $lon) {
            $isAffiliated = in_array($business->busines_id, $affiliatedIds);

            $km = $this->distancias->kilometros(
                $business->latitude !== null ? (float) $business->latitude : null,
                $business->longitude !== null ? (float) $business->longitude : null,
                $lat,
                $lon,
            );

            return [
                'business_id'   => $business->busines_id,
                'latitude'      => $business->latitude !== null ? (float) $business->latitude : null,
                'longitude'     => $business->longitude !== null ? (float) $business->longitude : null,
                'distance_km'   => $km === null ? null : round($km, 2),
                'delivery_fee'  => $this->distancias->paraDistancia($km),
                'in_range'      => $this->distancias->reparteHasta($km),
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
                'owners'        => $this->presentador->propietarios($business),
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
                'reviews' => $ligero ? [] : $this->presentador->resenas($business),
                'is_affiliated' => $isAffiliated,
            ];
        });

        return response()->json([
            'user_authenticated' => $userAuthenticated,
            'businesses'         => $formatted
        ]);
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

        /*
         * LAS PROMOCIONES VIGENTES DE ESTA TIENDA.
         *
         * Sin esto, una promoción solo la veía quien hubiera abierto la
         * notificación: entrar a la tienda por el buscador o por favoritos no
         * enseñaba nada, y el producto en promoción se veía igual que los
         * demás. Media promoción, y encima la mitad que menos vende.
         *
         * Se manda DOS VECES a propósito: la lista entera para la franja de
         * arriba —«hoy en esta tienda»— y la etiqueta pegada a cada producto,
         * porque el catálogo se recorre producto a producto y volver a mirar
         * la lista desde la app sería repetir acá la regla de a qué alcanza
         * cada una.
         */
        $promociones = Promotion::with('products')
            ->where('busines_id', $business->busines_id)
            ->where('state', Promotion::ENVIADA)
            ->get()
            ->filter(fn (Promotion $p) => $p->vigente());

        /** La que rebaja más de las que alcanzan a este producto. */
        $promoDe = function (int $productoId) use ($promociones) {
            $alcanzan = $promociones->filter(
                fn (Promotion $p) => $p->products->isEmpty()
                    || $p->products->contains(fn ($x) => (int) $x->products_id === $productoId),
            );

            if ($alcanzan->isEmpty()) {
                return null;
            }

            // Con varias, la que más rebaja sobre una unidad tipo. No es el
            // cálculo del carrito —ese lo hace `DescuentoPorPromocion` con las
            // cantidades reales—: acá solo se elige cuál anunciar.
            $mejor = $alcanzan->sortByDesc(
                fn (Promotion $p) => $p->descuentoSobre($p->minimoParaAplicar(), 1000),
            )->first();

            return [
                'promotion_id' => (int) $mejor->promotion_id,
                'texto'        => (string) $mejor->description,
                'regla'        => $mejor->reglaEnPalabras(),
                'hasta_texto'  => $mejor->hastaEnPalabras(),
            ];
        };

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
            'owners'        => $this->presentador->propietarios($business),
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
                ->map(function ($product) use ($estaAfiliado, $promoDe) {
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
                    // `null` cuando no hay ninguna. La app pinta la etiqueta
                    // solo si viene algo.
                    'promocion'  => $promoDe((int) $product->products_id),
                ];
            }),

            /*
             * Las promociones de la tienda, para la franja de arriba. Solo las
             * que DESCUENTAN: una que solo avisa —«traemos pan a las 4»— ya
             * llegó por la campana y anunciarla otra vez en la ficha sería
             * ruido sobre algo que no cambia ningún precio.
             */
            'promociones' => $promociones
                ->filter(fn (Promotion $p) => $p->descuenta())
                ->map(fn (Promotion $p) => [
                    'promotion_id' => (int) $p->promotion_id,
                    'texto'        => (string) $p->description,
                    'regla'        => $p->reglaEnPalabras(),
                    'hasta_texto'  => $p->hastaEnPalabras(),
                ])->values(),
            'reviews' => $this->presentador->resenas($business),
        ]);
    }
}
