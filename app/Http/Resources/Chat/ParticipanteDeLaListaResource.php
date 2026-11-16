<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QUIEN ESTA EN UNA CONVERSACION, EN LA LISTA DE CHATS. Y aqui hay un
 * hallazgo, no una variante.
 *
 * Era el `map` de `ChatController::getUserChats()`, que sirve
 * `POST /chats/user-chats`. Manda NUEVE campos donde la otra forma del mismo
 * participante —`ParticipanteDelChatResource`, que sirve `/chats/messages` y
 * `/chats/send-message`— manda cuatro.
 *
 * Los cinco de mas: `email`, `phone`, `address`, `qualification` y `state`.
 *
 * O SEA QUE LISTAR TUS CONVERSACIONES TE DEVUELVE EL CORREO, EL TELEFONO Y LA
 * DIRECCION de cada persona con la que has hablado. Comprobado contra la
 * respuesta real: `email` llega con dato; `phone` y `address` salen vacios en
 * la base de desarrollo, pero las claves viajan y en produccion las columnas
 * estan llenas.
 *
 * ES LA MISMA FUGA QUE YA SE CERRO DOS VECES EN ESTE REPOSITORIO, en los dos
 * listados del catalogo y en los favoritos: alli salian el documento, la
 * fecha de nacimiento y la direccion del propietario a cualquiera que abriera
 * la vitrina, y la regla que quedo escrita fue «si algun dia el panel
 * necesita esos campos, van por la API de administracion, que tiene puerta».
 * Aqui no se ha cerrado.
 *
 * SE CONSERVA TAL CUAL, a proposito: quitar cinco campos de una respuesta es
 * cambiar lo que reciben dos apps publicadas en las tiendas, y el telefono
 * puede estar ahi por una razon —llamar al tendero por un domicilio en
 * curso—. Cual de los cinco sobra es una decision de producto. Lo que se gana
 * moviendolo es que las dos formas estan ahora una al lado de la otra con la
 * diferencia escrita.
 */
class ParticipanteDeLaListaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $participant = $this->resource;

        return [
            'user_id' => $participant->user->user_id,
            'name' => $participant->user->name,
            'email' => $participant->user->email,
            'phone' => $participant->user->phone,
            'address' => $participant->user->address,
            'role_id' => $participant->role_id,
            'qualification' => $participant->user->qualification,
            'state' => $participant->user->state,
            'joined_at' => $participant->joined_at,
        ];
    }
}
