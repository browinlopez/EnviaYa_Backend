<?php

namespace App\Services;

/**
 * Las piezas de un negocio, tal como las espera la aplicacion.
 *
 * `index`, `indexByQualification` y `show` de `ConsultaDeNegociosController`
 * construyen la misma respuesta con variantes: son 178, 130 y 124 lineas con
 * un 59 % de coincidencia entre las dos primeras y **cincuenta lineas
 * identicas literalmente en las tres**.
 *
 * No es solo repeticion. Es que el dia que alguien anada un campo al listado
 * y no a la ficha, la app recibe un negocio distinto segun por donde entre, y
 * el fallo aparece en una pantalla que nadie toco.
 *
 * Aca viven los tres bloques que si son identicos —propietario, resenas y
 * producto—. Lo que cambia de verdad entre los tres metodos —que se incluye,
 * como se ordena, el modo ligero— se queda en el controlador, que es donde se
 * entiende.
 */
class NegocioParaLaApp
{
    /**
     * Del propietario solo sale lo que hace falta para comprar.
     *
     * Aca salian tambien su numero de documento, su fecha de nacimiento y su
     * direccion, a cualquiera que abriera el catalogo. Si algun dia el panel
     * necesita esos campos, van por la API de administracion, que tiene
     * puerta.
     */
    public function propietarios($negocio): array
    {
        return $negocio->owners->map(fn ($owner) => [
            'owner_id'      => $owner->owner_id,
            'user_id'       => $owner->user_id,
            'profile_photo' => $owner->profile_photo ?? 'https://example.com/default-user.png',
            'state'         => (bool) $owner->state,
        ])->all();
    }

    /** Las resenas del negocio, sin los datos de quien las escribio. */
    public function resenas($negocio): array
    {
        return $negocio->reviews->map(fn ($review) => [
            'review_id'  => $review->reviews_id ?? null,
            'buyer_id'   => $review->buyer_id,
            'rating'     => (float) ($review->qualification ?? 0),
            'comment'    => $review->comment ?? '',
            'created_at' => $review->created_at ?? null,
        ])->all();
    }

    /**
     * Un producto del catalogo.
     *
     * El precio solo viaja si quien pregunta esta afiliado a esa tienda. Sin
     * sesion o sin afiliacion va en 0, y la app lo pinta como bloqueado: es la
     * regla que impide leer los precios de un negocio con el que no se tiene
     * relacion.
     */
    public function producto($producto, bool $afiliado, $userId): array
    {
        return [
            'product_id'  => $producto->products_id,
            'name'        => $producto->name,
            'description' => $producto->description,
            'category_id' => $producto->category_id,
            'image'       => $producto->image,
            'state'       => (bool) $producto->state,
            'price'       => $userId && $afiliado ? ($producto->pivot->price ?? 0) : 0,
        ];
    }

    /** El catalogo entero de un negocio. */
    public function productos($negocio, bool $afiliado, $userId): array
    {
        return $negocio->products
            ->map(fn ($p) => $this->producto($p, $afiliado, $userId))
            ->all();
    }

    /** Los campos del negocio que van en las tres respuestas. */
    public function base($negocio): array
    {
        return [
            'business_id'   => $negocio->busines_id,
            'name'          => $negocio->name,
            'phone'         => $negocio->phone,
            'address'       => $negocio->address,
            'qualification' => (float) $negocio->qualification,
            'razon_social'  => $negocio->razonSocial_DCD,
            'NIT'           => $negocio->NIT,
            'logo'          => $negocio->logo ?? 'https://example.com/default-logo.png',
            'state'         => (bool) $negocio->state,
            'municipality'  => $negocio->municipality ? [
                'id'   => $negocio->municipality->id,
                'name' => $negocio->municipality->name,
            ] : null,
            'owner_count'   => $negocio->owners->count(),
        ];
    }
}
