<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\BusinessUserFavorite;
use App\Services\NegocioParaLaApp;
use App\Services\TarifaPorDistancia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    public function __construct(
        private readonly NegocioParaLaApp $presentador,
        private readonly TarifaPorDistancia $distancias,
    ) {
    }

    public function toggleFavorite(Request $request)
    {
        $request->validate([
            'user_id'    => 'required|integer|exists:user,user_id',
            'busines_id' => 'required|integer|exists:business,busines_id',
        ]);

        $userId     = $request->user_id;
        $businessId = $request->busines_id;

        $favorite = BusinessUserFavorite::where('user_id', $userId)
            ->where('busines_id', $businessId)
            ->first();

        if ($favorite) {
            // ya existe, eliminarlo
            $favorite->delete();
            return response()->json(['message' => 'Eliminado de favoritos']);
        } else {
            // no existe, crearlo
            BusinessUserFavorite::create([
                'user_id'    => $userId,
                'busines_id' => $businessId,
            ]);
            return response()->json(['message' => 'Agregado a favoritos']);
        }
    }


    // Listar favoritos del usuario
    public function myFavorites(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        $userId = $request->user_id;

        /*
         * Desde dónde mira quien pregunta, igual que los otros dos listados.
         *
         * Sin esto los favoritos eran el único sitio donde la tarifa salía
         * plana y donde no se sabía si la tienda reparte hasta la dirección
         * elegida. Opcional: sin coordenadas responde como antes.
         */
        $lat = $request->filled('lat') ? (float) $request->input('lat') : null;
        $lon = $request->filled('lng') ? (float) $request->input('lng') : null;

        /*
         * Favoritos de negocios ACTIVOS, y con sus productos activos.
         *
         * Esta era la puerta de atrás: el listado del catálogo sí filtraba por
         * `state`, pero acá no, así que desactivar una tienda desde el panel la
         * sacaba del inicio y la dejaba intacta en la pestaña de favoritos de
         * quien ya la tenía guardada — con su catálogo, sus precios y el botón
         * de pedir.
         */
        /*
         * MODO LIGERO, igual que en el listado del inicio.
         *
         * Esta pantalla pinta TARJETAS de tienda —logo, nombre, nota, tarifa—
         * y no enseña ni un producto. Aun asi la respuesta traia el catalogo
         * entero de cada favorito: medido con la base de demostracion, 38 KB
         * para UN solo favorito, de los cuales 36 eran 185 productos que nadie
         * mira. Con cinco favoritos son casi 200 KB cada vez que se abre la
         * pestaña.
         *
         * Y no es solo gasto de datos: la peticion mas pesada es la que se
         * corta a medias en una red lenta, que es como el listado del inicio
         * acababa fallando en el emulador.
         *
         * Va OPCIONAL, como en el otro: las versiones de la app ya instaladas
         * no mandan la bandera y siguen recibiendo lo de siempre.
         */
        $ligero = $request->boolean('light');

        $favorites = BusinessUserFavorite::where('user_id', $userId)
            ->whereHas('business', fn ($q) => $q->where('state', 1))
            ->with(array_filter([
                'business.owners',
                'business.municipality',
                // Ni siquiera se traen de la base si no van a viajar.
                $ligero ? null : 'business.reviews',
            ]) + ($ligero ? [] : [
                'business.products' => fn ($q) => $q->where('products.state', 1),
            ]))
            ->get();

        // Transformamos a un array similar al index
        $formatted = $favorites->map(function ($favorite) use ($lat, $lon, $ligero) {
            $business = $favorite->business;

            $km = $this->distancias->kilometros(
                $business->latitude !== null ? (float) $business->latitude : null,
                $business->longitude !== null ? (float) $business->longitude : null,
                $lat,
                $lon,
            );

            return [
                'favorite_id'   => $favorite->id,
                'business_id'   => $business->busines_id,
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
                 * Acá salían su número de documento, su fecha de nacimiento,
                 * su teléfono secundario y las notas internas que le haya
                 * puesto el equipo. La misma fuga se cerró en los dos listados
                 * del catálogo y ESTE se quedó sin tocar: bastaba con marcar
                 * la tienda como favorita para volver a leerlo todo. Ahora usa
                 * el mismo presentador que los otros, para que no pueda volver
                 * a divergir.
                 */
                'owners'        => $this->presentador->propietarios($business),
                'products' => $ligero ? [] : $business->products->map(function ($product) {
                    return [
                        'product_id'  => $product->products_id,
                        'name'        => $product->name,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'image'       => $product->image,
                        'state'       => (bool) $product->state,
                        'price'       => $product->pivot->price ?? null,
                    ];
                }),
                'reviews' => $ligero ? [] : $business->reviews->map(function ($review) {
                    return [
                        'review_id'  => $review->reviews_id ?? null,
                        'buyer_id'   => $review->buyer_id,
                        'rating'     => (float) $review->qualification ?? 0,
                        'comment'    => $review->comment ?? '',
                        'created_at' => $review->created_at ?? null,
                    ];
                }),
            ];
        });

        return response()->json([
            'favorites' => $formatted
        ]);
    }
}
