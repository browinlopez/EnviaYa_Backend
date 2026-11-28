<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Events\MessageSent;
use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ChatsDeUnaPersonaRequest;
use App\Http\Requests\Chat\MandarMensajeRequest;
use App\Http\Requests\Chat\MarcarMensajeRequest;
use App\Http\Requests\Chat\MensajesDelChatRequest;
use App\Http\Resources\Chat\ChatDeLaListaResource;
use App\Http\Resources\Chat\ChatResource;
use App\Http\Resources\Chat\MensajeResource;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\User;
use App\Services\Avisos;
use App\Services\PreordenDelChat;
use App\Support\ResumenDeMensaje;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    use ComprobarPertenencia;

    public function sendMessage(MandarMensajeRequest $request)
    {
        // Escribir EN NOMBRE DE OTRO: `user_id` venia del cuerpo, asi que
        // se podian mandar mensajes haciendose pasar por cualquiera.
        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        return DB::transaction(function () use ($request) {
            // Obtener usuarios
            $sender = User::findOrFail($request->user_id);
            $recipient = User::findOrFail($request->recipient_id);

            /*
             * DOS MENSAJES A LA VEZ CREABAN DOS CHATS.
             *
             * Buscar y crear no son atómicos: dos envíos casi simultáneos
             * —«Buenos días» dos veces, un toque doble— no encontraban el chat
             * y lo creaban cada uno. En producción quedaron dos conversaciones
             * con la misma persona, a un segundo de diferencia, y el mensaje
             * partido entre las dos.
             *
             * Bloquear las filas de ambos usuarios pone en fila a quien llegue
             * segundo: espera a que el primero confirme y ya encuentra el chat.
             * Se bloquean en el mismo orden siempre para no cruzarse.
             */
            User::whereIn('user_id', collect([$sender->user_id, $recipient->user_id])->sort()->values())
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();

            /*
             * DE QUE MOSTRADOR ES, Y POR QUE ENTRA EN LA BUSQUEDA.
             *
             * Antes el chat se buscaba solo por la pareja de personas. Si el
             * mismo vecino le escribia a los DOS locales del mismo dueno, los
             * dos hilos se fundian en UNO: la pregunta del medicamento y la
             * del almuerzo mezcladas por fecha, sin forma de separarlas.
             *
             * Con el negocio en la busqueda son dos conversaciones, cada una
             * en su local. Y un chat SIN negocio --un domiciliario, soporte--
             * sigue siendo el suyo y no se mezcla con los de mostrador.
             */
            $negocioId = $this->negocioDeLaConversacion($request, $sender, $recipient);

            // Una preorden solo la arma el cliente o quien administra ese negocio.
            $tarjeta = PreordenDelChat::tarjeta((string) $request->content);
            if ($tarjeta !== null && ! app(PreordenDelChat::class)->puedeMandar($sender, $negocioId, $tarjeta)) {
                return response()->json(['message' => 'No puedes mandar ese pedido en esta conversación.'], 403);
            }

            $chat = Chat::where('type', 'private')
                ->where(fn ($q) => $negocioId === null
                    ? $q->whereNull('busines_id')
                    : $q->where('busines_id', $negocioId))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $sender->user_id))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $recipient->user_id))
                ->first();

            // Si no existe, crear el chat
            if (! $chat) {
                $chat = Chat::create([
                    'type' => 'private', // siempre privado para chats 1 a 1
                    'busines_id' => $negocioId,
                ]);

                // Agregar participantes usando 'rol' de la tabla user
                ChatParticipant::insert([
                    [
                        'chat_id' => $chat->chat_id,
                        'user_id' => $sender->user_id,
                        'role_id' => $sender->rol,
                        'joined_at' => now(),
                    ],
                    [
                        'chat_id' => $chat->chat_id,
                        'user_id' => $recipient->user_id,
                        'role_id' => $recipient->rol,
                        'joined_at' => now(),
                    ],
                ]);
            }
            $message = Message::create([
                'chat_id' => $chat->chat_id,
                'user_id' => $sender->user_id,
                'role_id' => $sender->rol,
                'content' => $request->content,
            ]);

            if ($tarjeta !== null) {
                app(PreordenDelChat::class)->registrar($message);
            }

            // Quien escribe ya vio la conversación hasta su propio mensaje.
            $this->marcarLeido($chat->chat_id, (int) $sender->user_id);

            /*
             * El mensaje sale por el websocket, además de guardarse.
             *
             * Esta línea llevaba comentada desde siempre, así que el chat no
             * era en tiempo real: la app preguntaba al servidor cada 10
             * segundos. Eso significaba hasta 10 s de retraso para ver una
             * respuesta y una petición constante por cada persona con el chat
             * abierto, cliente, tienda o domiciliario.
             *
             * Se emite dentro de la transacción a propósito NO: va después de
             * guardar, y si el envío por socket falla —Reverb caído, red
             * intermitente— el mensaje ya está en la base y la app lo verá en
             * la siguiente consulta. Perder el aviso es tolerable; perder el
             * mensaje no.
             */
            try {
                broadcast(new MessageSent($message));
            } catch (\Throwable $e) {
                Log::warning('No se pudo anunciar el mensaje de chat', [
                    'message_id' => $message->message_id,
                    'error' => $e->getMessage(),
                ]);
            }

            /*
             * Y al telefono del otro, que es lo que faltaba.
             *
             * Igual que el resto del chat, esto solo salia por websocket: alguien
             * preguntaba «tiene leche deslactosada?» y si el otro lado tenia la
             * app cerrada, el mensaje esperaba a que la abriera. En un pedido en
             * curso eso es un cliente esperando una respuesta que nadie va a ver.
             *
             * EL TITULO ES EL NOMBRE DE QUIEN ESCRIBE, no «Mensaje nuevo»: en la
             * barra de notificaciones es lo unico que se lee entero, y saber
             * quien escribe es la mitad de la decision de abrir.
             *
             * Se recorta el cuerpo porque un mensaje largo se corta igual en la
             * pantalla bloqueada, y mandarlo entero solo gasta carga util.
             *
             * Y NO SE MANDA EL CONTENIDO TAL CUAL. El chat tambien lleva
             * tarjetas --el pedido que arma el tendero viaja como JSON-- y
             * copiarlas al aviso ponia esto en la campana del cliente:
             *
             *     {"type":"preorder","preorder_id":1792531797473,...
             *
             * `ResumenDeMensaje` traduce esas tarjetas a una frase y deja el
             * texto normal como estaba.
             */
            /*
             * Si escribe LA TIENDA, el aviso dice el nombre de la tienda. El
             * cliente le escribió a «Tienda de prueba», no a la persona que la
             * atiende: ver «Geovanny Boom» en la campana no le dice nada.
             */
            $deQuien = (string) ($sender->name ?? '');
            if ($chat->busines_id && (int) $sender->rol === 2) {
                $deQuien = (string) ($chat->negocio()->value('name') ?: $deQuien);
            }

            Avisos::para(
                $recipient->user_id,
                'mensaje_nuevo',
                ResumenDeMensaje::para((string) $message->content),
                [
                    'chat_id' => $chat->chat_id,
                    // Para que tocar el aviso abra ESE chat: la pantalla necesita
                    // saber con quien se habla, no solo el numero del chat.
                    'remitente_id' => $sender->user_id,
                    'remitente_nombre' => $deQuien,
                    // Con quién se habla: el chat abierto desde el aviso dice
                    // «Cliente», «Negocio» o «Domiciliario» bajo el nombre.
                    'remitente_rol' => (int) $sender->rol,
                    // De qué negocio es el chat: abierto desde el aviso, una
                    // pre-orden vieja sin negocio no se podía repetir.
                    'business_id' => $chat->busines_id,
                ],
                titulo: $deQuien !== '' ? $deQuien : 'Mensaje nuevo',
            );

            return response()->json([
                'message' => 'Mensaje enviado',
                'chat_id' => $chat->chat_id,
                'data' => $message->load('user'),
            ], 201);
        });
    }

    public function getMessages(MensajesDelChatRequest $request)
    {
        // si viene chat_id se comporta igual que antes
        // Las dos formas de pedirla las valida `MensajesDelChatRequest`.
        if ($request->filled('chat_id')) {
            $chat = Chat::with(['messages.user', 'participants.user'])
                ->find($request->chat_id);

            /*
             * SOLO QUIEN ESTA EN LA CONVERSACION.
             *
             * `chat_id` llegaba en el cuerpo y no se comparaba con nadie:
             * con cualquier cuenta se leia lo que se escribieron un
             * comprador, un tendero y un domiciliario. Los identificadores
             * son correlativos, asi que recorrerlos no requiere ingenio.
             */
            if ($chat && ! $this->participaEnElChat($request, $chat)) {
                return response()->json(['message' => 'Esa conversacion no es tuya.'], 403);
            }

            if (! $chat) {
                return response()->json([
                    'chat_id' => $request->chat_id,
                    'messages' => [],
                    'participants' => [],
                ]);
            }

            // Abrirla es leerla: sus mensajes dejan de contar como nuevos.
            $this->marcarLeido((int) $chat->chat_id, (int) $request->user()?->user_id);

            return $this->formatChatResponse($chat);
        }

        // Sin chat_id, la pareja: ya validada por `MensajesDelChatRequest`.
        $userId = $request->input('user_id');
        $recipientId = $request->input('recipient_id');

        // buscar chat privado existente entre esos dos usuarios
        $chat = Chat::where('type', 'private')
            ->whereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->whereHas('participants', function ($q) use ($recipientId) {
                $q->where('user_id', $recipientId);
            })
            ->with(['messages.user', 'participants.user'])
            ->first();

        if (! $chat) {
            // no hay chat creado todavía, devolver vacío
            return response()->json([
                'chat_id' => null,
                'messages' => [],
                'participants' => [],
            ]);
        }

        $this->marcarLeido((int) $chat->chat_id, (int) $request->user()?->user_id);

        return $this->formatChatResponse($chat);
    }

    public function updateMessage(MarcarMensajeRequest $request)
    {

        $message = Message::findOrFail($request->message_id);

        /*
         * Editar el mensaje de otro: solo su autor... SALVO para contestar una
         * pre-orden.
         *
         * La pre-orden la escribe el tendero y la confirma o la cancela el
         * cliente, y eso se guarda cambiando el `status` de la tarjeta. Con la
         * regla de «solo el autor» el cliente recibía un 403 que la app se
         * tragaba: los productos sí pasaban al carrito, pero la tarjeta se
         * quedaba «pendiente» para siempre en los dos teléfonos.
         *
         * Se permite lo justo: la otra persona del chat cambia el ESTADO de
         * una tarjeta de pre-orden a confirmada o cancelada. Los productos,
         * las cantidades y los precios tienen que llegar idénticos; si cambia
         * cualquier otra cosa, vuelve a mandar la regla del autor.
         */
        if (PreordenDelChat::tarjeta((string) $message->content) !== null) {
            return $this->responderPreorden($request, $message);
        }

        if ($no = $this->negarCuentaAjena($request, $message->user_id)) {
            return $no;
        }

        $message->content = json_encode($request->content);
        $message->save();

        return response()->json([
            'success' => true,
            'message' => (new MensajeResource($message))->resolve(),
        ]);
    }

    private function responderPreorden(Request $request, Message $message)
    {
        $yo = (int) $request->user()?->user_id;

        $resultado = DB::transaction(function () use ($message, $yo, $request) {
            $fresco = Message::whereKey($message->message_id)->lockForUpdate()->firstOrFail();
            $nueva = app(PreordenDelChat::class)->responder($fresco, $yo, (array) $request->input('content'));
            if (is_array($nueva)) {
                app(PreordenDelChat::class)->aplicar($fresco, $nueva, $yo);
                $fresco->refresh();
                $nueva = PreordenDelChat::tarjeta((string) $fresco->content) ?? $nueva;
            }

            return [$fresco, $nueva];
        });

        [$message, $nueva] = $resultado;

        if (is_string($nueva)) {
            $codigo = match ($nueva) {
                'cerrada' => 409,
                'ajena', 'no_permitido' => 403,
                default => 422,
            };

            return response()->json([
                'message' => $codigo === 409 ? 'Ese pedido ya se contestó.' : 'No puedes cambiar ese pedido así.',
                'reason' => $nueva,
            ], $codigo);
        }

        $this->anunciarRespuesta($message, $nueva, $yo);

        return response()->json([
            'success' => true,
            'message' => (new MensajeResource($message))->resolve(),
        ]);
    }

    /** @param  array<string, mixed>  $tarjeta */
    private function anunciarRespuesta(Message $message, array $tarjeta, int $yo): void
    {
        // Sin esto el otro teléfono solo veía el cambio al minuto, con el sondeo.
        try {
            broadcast(new MessageSent($message));
        } catch (\Throwable $e) {
            Log::warning('No se pudo anunciar la respuesta a la preorden', ['error' => $e->getMessage()]);
        }

        $chat = Chat::find($message->chat_id);
        $negocio = $chat?->busines_id ? (string) $chat->negocio()->value('name') : '';
        $actor = User::find($yo);
        $esDelNegocio = $negocio !== '' && (int) $actor?->rol === 2;
        $titulo = $esDelNegocio ? $negocio : (string) ($actor->name ?? 'VeciPa\'Ya');
        $total = '$'.number_format((float) ($tarjeta['total'] ?? PreordenDelChat::total($tarjeta['items'] ?? [])), 0, ',', '.');

        $texto = match (true) {
            $tarjeta['status'] === PreordenDelChat::CONFIRMADA && $esDelNegocio => "Confirmó tu pedido: {$total}. Ya puedes pagar.",
            $tarjeta['status'] === PreordenDelChat::CONFIRMADA => "Confirmó el pedido: {$total}.",
            $esDelNegocio => 'No pudo atender tu pedido.',
            default => 'Canceló el pedido.',
        };

        $otros = ChatParticipant::where('chat_id', $message->chat_id)->where('user_id', '!=', $yo)->pluck('user_id');
        foreach ($otros as $otro) {
            Avisos::para((int) $otro, 'preorden_respondida', $texto, [
                'chat_id' => $message->chat_id,
                'message_id' => $message->message_id,
                'business_id' => $chat?->busines_id,
                'status' => $tarjeta['status'],
                'remitente_id' => $yo,
                'remitente_nombre' => $titulo,
                'remitente_rol' => (int) $actor?->rol,
            ], titulo: $titulo);
        }
    }

    /**
     * Arma el JSON de salida para un chat dado
     */
    protected function formatChatResponse(Chat $chat)
    {
        return response()->json((new ChatResource($chat))->resolve());
    }

    public function getUserChats(ChatsDeUnaPersonaRequest $request)
    {

        // Se precarga el último mensaje de cada chat: sin él la lista solo
        // podía mostrar el nombre del contacto, sin vista previa ni fecha.
        /*
         * EL LOCAL QUE SE ESTA MIRANDO, SI LO DICEN.
         *
         * Se incluyen tambien los chats SIN negocio, y no es por timidez:
         * un chat sin negocio es soporte respondiendo un reclamo --que el
         * dueno tiene que ver mire el local que mire-- o una conversacion
         * vieja de cuando esto no se guardaba. Filtrar en seco los haria
         * desaparecer de los dos locales, que se lee como datos perdidos.
         */
        $negocioId = $request->filled('busines_id') ? (int) $request->busines_id : null;

        $chats = Chat::whereHas('participants', function ($q) use ($request) {
            $q->where('user_id', $request->user_id);
        })
            ->when($negocioId, fn ($q) => $q->where(
                fn ($w) => $w->where('busines_id', $negocioId)->orWhereNull('busines_id'),
            ))
            ->with([
                'participants.user',
                'negocio:busines_id,name',
                'messages' => fn ($q) => $q->latest('created_at')->limit(1),
            ])
            ->get()
            // Los chats con actividad reciente van primero; los que no tienen
            // ningún mensaje quedan al final.
            ->sortByDesc(fn ($chat) => optional($chat->messages->first())->created_at)
            ->values();

        /*
         * CUÁNTOS SIN LEER, por conversación y en UNA consulta: los mensajes
         * de los demás después del último que esta persona leyó.
         * La app ya pintaba `unread_count` y nunca llegaba.
         */
        $yo = (int) $request->user()->user_id;
        $sinLeer = $chats->isEmpty() ? collect() : DB::table('messages as m')
            ->join('chat_participants as p', function ($j) use ($yo) {
                $j->on('p.chat_id', '=', 'm.chat_id')->where('p.user_id', '=', $yo);
            })
            ->whereIn('m.chat_id', $chats->pluck('chat_id'))
            ->where('m.user_id', '!=', $yo)
            // Por número de mensaje y no por hora: ver la migración
            // `hasta_donde_leyo_cada_uno`.
            ->where(fn ($q) => $q->whereNull('p.last_read_message_id')
                ->orWhereColumn('m.message_id', '>', 'p.last_read_message_id'))
            ->groupBy('m.chat_id')
            ->selectRaw('m.chat_id, COUNT(*) as cuantos')
            ->pluck('cuantos', 'chat_id');

        $formattedChats = $chats->map(
            fn ($chat) => (new ChatDeLaListaResource($chat))->resolve()
                + ['unread_count' => (int) ($sinLeer[$chat->chat_id] ?? 0)],
        );

        return response()->json($formattedChats);
    }

    /**
     * De que negocio es esta conversacion, si es de alguno.
     *
     * TRES CAMINOS, Y EL ORDEN IMPORTA:
     *
     *  1. Lo que diga quien escribe. El panel sabe que local esta mirando
     *     y lo manda. Pero NO se acepta a ciegas: el negocio tiene que ser
     *     de uno de los dos que hablan, o cualquiera podria colgar su
     *     conversacion del mostrador de otro.
     *
     *  2. Deducirlo, cuando no hay duda: uno de los dos es dueno y tiene
     *     UN solo negocio. Esto es lo que hace que la app publicada
     *     --que no manda nada-- siga clasificando bien sin tocarla.
     *
     *  3. Nulo. Dos duenos, dos negocios, o ningun dueno: no se adivina.
     *     Mover una conversacion a un mostrador al azar es peor que
     *     dejarla sin mostrador.
     */
    private function negocioDeLaConversacion(Request $request, User $emisor, User $destino): ?int
    {
        $suyos = fn (User $u) => DB::table('owner_busines as ob')
            ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
            ->where('o.user_id', $u->user_id)
            ->pluck('ob.busines_id')
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->values();

        $deLosDos = $suyos($emisor)->merge($suyos($destino))->unique()->values();

        if ($request->filled('busines_id')) {
            $pedido = (int) $request->busines_id;

            if ($deLosDos->contains($pedido)) {
                return $pedido;
            }

            /*
             * Un negocio que no es de ninguno de los dos se IGNORA, y se sigue
             * al paso siguiente en vez de devolver nulo.
             *
             * La diferencia importa: si una app vieja manda un negocio
             * caducado, devolver nulo dejaria la conversacion sin mostrador y
             * el dueno tendria que buscarla a mano. Ignorarlo y deducir pone
             * la conversacion donde le toca cuando el dueno tiene uno solo.
             */
        }

        return $deLosDos->count() === 1 ? (int) $deLosDos->first() : null;
    }

    /** Lo que había en el chat hasta ahora, esta persona ya lo vio. */
    private function marcarLeido(int $chatId, int $userId): void
    {
        if ($chatId <= 0 || $userId <= 0) {
            return;
        }

        $ultimo = DB::table('messages')->where('chat_id', $chatId)->max('message_id');
        if ($ultimo === null) {
            return;
        }

        DB::table('chat_participants')
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->update(['last_read_message_id' => $ultimo]);

        /*
         * Y SUS AVISOS DE «MENSAJE NUEVO», TAMBIÉN LEÍDOS. Si no, la campana
         * seguía contando como pendientes mensajes que ya se leyeron en el
         * chat, y la campana y la barra decían números distintos.
         */
        DB::table('notifications')
            ->where('user_id', $userId)
            ->where('tipo', 'mensaje_nuevo')
            ->where('read', false)
            ->where('datos->chat_id', $chatId)
            ->update(['read' => true]);
    }

    /**
     * Si quien pregunta esta en la conversacion.
     *
     * El equipo interno tambien: soporte necesita poder leer un chat cuando
     * alguien reclama, y su propia puerta ya esta comprobada aparte.
     */
    private function participaEnElChat(Request $request, Chat $chat): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if ((int) $user->rol === 4) {
            return true;
        }

        return $chat->participants->contains(
            fn ($p) => (int) $p->user_id === (int) $user->user_id,
        );
    }
}
