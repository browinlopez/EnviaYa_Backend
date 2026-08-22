<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qué negocios administra una persona.
 *
 * La cadena de propiedad tiene tres saltos y está escrita a mano en varios
 * sitios del proyecto:
 *
 *     user  →  owner (owner.user_id)  →  owner_busines  →  business
 *
 * Cada vez que alguien la reescribe hay una oportunidad de olvidarse de un
 * salto, y olvidarse acá no da un error: da los datos de otro. Por eso vive en
 * un solo sitio.
 *
 * Un tendero puede tener MÁS DE UN negocio —hoy mismo hay dos así en la base—,
 * de modo que esto devuelve una lista y no un identificador. La app móvil se
 * queda con `businesses[0]` y nunca deja administrar el segundo; el panel no
 * repite esa limitación.
 */
class NegocioDelUsuario
{
    /**
     * Los negocios de esta persona, ordenados por nombre.
     *
     * Ordenados y no en el orden que devuelva la base: el selector del panel
     * los enseña en una lista, y una lista que cambia de orden entre recargas
     * hace que la gente elija el equivocado por costumbre.
     */
    public function negociosDe(?int $userId): Collection
    {
        if (!$userId) {
            return collect();
        }

        return Business::query()
            ->join('owner_busines as ob', 'ob.busines_id', '=', 'business.busines_id')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $userId)
            ->orderBy('business.name')
            ->select('business.*')
            ->get();
    }

    /**
     * ¿Puede esta persona administrar este negocio?
     *
     * Con EXISTS y no contando: `owner_busines` no tiene índice único, así que
     * una fila duplicada haría que un conteo diera dos y una comparación con
     * uno fallara. Lo único que importa acá es si hay al menos una.
     */
    public function administra(?int $userId, $businessId): bool
    {
        if (!$userId || !$businessId) {
            return false;
        }

        return DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $userId)
            ->where('ob.busines_id', $businessId)
            ->exists();
    }
}
