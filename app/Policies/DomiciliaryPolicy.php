<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * QUIÉN PUEDE VER Y TOCAR A UN DOMICILIARIO.
 *
 * §08 del estándar pide Policy o Gate por recurso, y hasta hoy esto vivía en
 * dos métodos privados de dos controladores distintos:
 *
 *   DomiciliaryController::puedeVerYEditarDomiciliario()
 *   ConsultaDePedidosController::puedeVerAlDomiciliario()
 *
 * LAS REGLAS NO CAMBIAN NI UNA COMA. Lo que cambia es que están en el sitio
 * que el estándar nombra y se invocan igual desde cualquier parte, en vez de
 * ser privadas de quien las escribió primero. Esa era la parte mala de
 * tenerlas en el controlador: el tercer sitio que necesitara la misma regla
 * la habría escrito otra vez, y la copia que se olvide de actualizar es la que
 * manda.
 *
 * LO QUE ESTO NO SUSTITUYE, Y ES IMPORTANTE. El middleware de grupo sigue
 * donde está. `EXCEPCIONES.md` razona por qué es la primera línea y el
 * argumento es bueno: **el middleware falla cerrado** —el endpoint que no
 * entra en el grupo no responde— y una Policy olvidada falla abierta. Así que
 * esto es la segunda capa, que es lo que §35 llama defensa en profundidad, no
 * un reemplazo. Quitar el middleware porque «ya hay Policy» sería cambiar una
 * garantía por una costumbre.
 */
class DomiciliaryPolicy
{
    /**
     * Ver y editar su ficha: él mismo, o el dueño de una tienda a la que
     * reparte.
     *
     * Viene de `DomiciliaryController::puedeVerYEditarDomiciliario()`, tal
     * cual. VER Y EDITAR LLEVAN LA MISMA REGLA a propósito: se llamaba
     * `puedeEditar…` y por eso `showDomiciliary` se quedó sin acotar mientras
     * `updateDomiciliary` sí lo estaba —arreglar la escritura y olvidar la
     * lectura deja la misma puerta abierta en modo consulta—. El nombre lleva
     * las dos cosas para que se note cuando aparezca un tercer método.
     *
     * Recibe el USUARIO objetivo y no el domiciliario, porque los dos sitios
     * que la llaman tienen a mano el usuario: uno viene de `user_id` en la
     * petición y el otro de `$domiciliary->user`.
     */
    public function verYEditar(?User $autenticado, User $objetivo): bool
    {
        if (! $autenticado) {
            return false;
        }

        if ((int) $autenticado->user_id === (int) $objetivo->user_id) {
            return true;
        }

        $domiciliary = $objetivo->domiciliary;

        if (! $domiciliary || ! $autenticado->owner) {
            return false;
        }

        $negociosPropios = $autenticado->owner->businesses->pluck('busines_id');

        return $domiciliary->businesses()
            ->whereIn('business.busines_id', $negociosPropios)
            ->exists();
    }

    /**
     * Ver POR DÓNDE VA: las tres relaciones que dan derecho, más el equipo.
     *
     * Viene de `ConsultaDePedidosController::puedeVerAlDomiciliario()`, tal
     * cual, y es una regla distinta de la de arriba a propósito: saber dónde
     * está una persona ahora mismo no es lo mismo que ver su ficha. Por eso el
     * comprador entra aquí —mientras su pedido está vivo— y no allí.
     *
     * Consultas directas y no relaciones: acá solo interesa el sí o el no, y
     * esto se llama en el camino del seguimiento, que la app refresca.
     */
    public function verUbicacion(?User $usuario, int $domiciliaryId): bool
    {
        if (! $usuario) {
            return false;
        }

        // El equipo, por su propia puerta (rol 4), igual que en el resto.
        if ((int) $usuario->rol === 4) {
            return true;
        }

        // El propio domiciliario.
        $esElMismo = DB::table('domiciliary')
            ->where('domiciliary_id', $domiciliaryId)
            ->where('user_id', $usuario->user_id)
            ->exists();

        if ($esElMismo) {
            return true;
        }

        // El negocio al que reparte: su tendero necesita ver su flota.
        $esDeSuNegocio = DB::table('business_domiciliary as bd')
            ->join('owner_busines as ob', 'ob.busines_id', '=', 'bd.busines_id')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('bd.domiciliary_id', $domiciliaryId)
            ->where('o.user_id', $usuario->user_id)
            ->exists();

        if ($esDeSuNegocio) {
            return true;
        }

        /*
         * Y el comprador que lo tiene encima: solo mientras el pedido está
         * vivo. Cuando se entrega, deja de poder mirar dónde anda.
         */
        return DB::table('orderssales as o')
            ->join('buyer as b', 'b.buyer_id', '=', 'o.buyer_id')
            ->where('o.domiciliary_id', $domiciliaryId)
            ->whereIn('o.state', [2, 3])
            ->where('b.user_id', $usuario->user_id)
            ->exists();
    }

    /**
     * El modelo existe para que la Policy quede atada al recurso que nombra,
     * que es lo que §08 pide. No todas las reglas reciben el modelo
     * —`verUbicacion` recibe el identificador porque es lo único que trae la
     * petición— y forzarlas a cargarlo seria una consulta por nada.
     */
    public function ver(?User $usuario, Domiciliary $domiciliary): bool
    {
        return $domiciliary->user !== null
            && $this->verYEditar($usuario, $domiciliary->user);
    }
}
