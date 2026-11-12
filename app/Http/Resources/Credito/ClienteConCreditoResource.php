<?php

declare(strict_types=1);

namespace App\Http\Resources\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UN CLIENTE Y SU CUPO DE CREDITO, en el panel del tendero.
 *
 * Era el cierre `->map()` de `CreditosDelNegocioController::index()`. Ocho
 * claves: quien es, si esta afiliado a la tienda, y las tres cifras del cupo.
 *
 * PUBLICA EL CORREO Y EL TELEFONO DEL CLIENTE, y aqui si corresponde: son los
 * clientes de ESA tienda, la ruta va detras de `EnsureBusinessOwner` --que
 * resuelve el negocio desde la sesion y no desde un identificador del
 * cuerpo-- y fiar sin poder llamar a quien le fias no tiene sentido.
 *
 * Se dice porque la misma pareja de campos SI fue un problema en otras dos
 * respuestas de este repositorio: en el catalogo publico, donde salia el
 * propietario de cualquier tienda a cualquiera, y en la lista de chats, donde
 * el participante lleva nueve campos en vez de cuatro. La diferencia no es el
 * campo: es quien pregunta y por quien.
 *
 * `limite`, `usado` y `disponible` las calcula `CreditoDeTienda::cupo()` y
 * viajan tal cual. No se recalculan aqui: dos sitios que sumen lo mismo por
 * su cuenta acaban dando cifras distintas el dia que una regla cambia.
 */
class ClienteConCreditoResource extends JsonResource
{
    /**
     * @param  array{limite: mixed, usado: mixed, disponible: mixed}  $cupo
     */
    public function __construct(
        object $usuario,
        private readonly array $cupo,
        private readonly bool $afiliado,
    ) {
        parent::__construct($usuario);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'user_id' => (int) $u->user_id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'afiliado' => $this->afiliado,
            'limite' => $this->cupo['limite'],
            'usado' => $this->cupo['usado'],
            'disponible' => $this->cupo['disponible'],
        ];
    }
}
