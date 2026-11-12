<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AlternarFavoritoRequest;
use App\Http\Requests\Business\MisFavoritosRequest;
use App\Http\Resources\Negocio\NegocioFavoritoResource;
use App\Http\Resources\Negocio\PropietarioResource;
use App\Http\Resources\Negocio\ResenaDelNegocioResource;
use App\Http\Resources\Producto\ProductoFavoritoResource;
use App\Models\Business\BusinessUserFavorite;
use App\Services\TarifaPorDistancia;

class FavoriteController extends Controller
{
    /*
     * Sin el presentador: la forma de cada pieza vive en su Resource. Ver
     * `PropietarioResource`, `ProductoFavoritoResource` y
     * `ResenaDelNegocioResource`.
     */
    public function __construct(
        private readonly TarifaPorDistancia $distancias,
    ) {}

    public function toggleFavorite(AlternarFavoritoRequest $request)
    {

        $userId = $request->user_id;
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
                'user_id' => $userId,
                'busines_id' => $businessId,
            ]);

            return response()->json(['message' => 'Agregado a favoritos']);
        }
    }

    // Listar favoritos del usuario
    public function myFavorites(MisFavoritosRequest $request)
    {

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

            return (new NegocioFavoritoResource(
                $business,
                favoritoId: (int) $favorite->id,
                reparto: [
                    'distance_km' => $km === null ? null : round($km, 2),
                    'delivery_fee' => $this->distancias->paraDistancia($km),
                    'in_range' => $this->distancias->reparteHasta($km),
                ],
                /*
                 * OJO CON EL PRECIO: `ProductoFavoritoResource` NO comprueba
                 * la afiliacion, al contrario que los dos listados del
                 * catalogo. Esta medido y escrito en la cabecera de esa clase
                 * y en la de `NegocioFavoritoResource`; es un asunto de
                 * producto sin decidir, no una errata.
                 */
                productos: $ligero
                    ? []
                    : ProductoFavoritoResource::collection($business->products)->resolve(),
                resenas: $ligero
                    ? []
                    : ResenaDelNegocioResource::collection($business->reviews)->resolve(),
            ))->resolve();
        });

        return response()->json([
            'favorites' => $formatted,
        ]);
    }
}
