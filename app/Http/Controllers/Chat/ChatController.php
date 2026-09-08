<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Events\MessageSent;
use App\Helper\ReverbClient;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Avisos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    use ComprobarPertenencia;

    /**
     * Crear un nuevo chat
     */
    public function createChat(Request $request)
    {
        $request->validate([
            'type' => 'required|in:private,group',
            'participants' => 'required|array|min:2',
            'participants.*.user_id' => 'required|exists:user,user_id',
            'participants.*.role_id' => 'required|exists:rol,rol_id',
        ]);

        return DB::transaction(function () use ($request) {
            $chat = Chat::create(['type' => $request->type]);

            foreach ($request->participants as $participant) {
                ChatParticipant::create([
                    'chat_id' => $chat->chat_id,
                    'user_id' => $participant['user_id'],
                    'role_id' => $participant['role_id']
                ]);
            }

            return response()->json([
                'message' => 'Chat creado con éxito',
                'chat' => $chat->load('participants.user')
            ], 201);
        });
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:user,user_id',
            'recipient_id' => 'required|exists:user,user_id',
            'content' => 'required|string|max:1000',
        ]);

        // Escribir EN NOMBRE DE OTRO: `user_id` venia del cuerpo, asi que
        // se podian mandar mensajes haciendose pasar por cualquiera.
        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        return DB::transaction(function () use ($request) {
            // Obtener usuarios
            $sender = User::findOrFail($request->user_id);
            $recipient = User::findOrFail($request->recipient_id);

            // Revisar si ya existe un chat privado entre ambos
            $chat = Chat::where('type', 'private')
                ->whereHas('participants', fn($q) => $q->where('user_id', $sender->user_id))
                ->whereHas('participants', fn($q) => $q->where('user_id', $recipient->user_id))
                ->first();

            // Si no existe, crear el chat
            if (!$chat) {
                $chat = Chat::create(['type' => 'private']); // siempre privado para chats 1 a 1

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
                    'error'      => $e->getMessage(),
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
             */
            Avisos::para(
                $recipient->user_id,
                'mensaje_nuevo',
                \Illuminate\Support\Str::limit((string) $message->content, 120),
                [
                    'chat_id'          => $chat->chat_id,
                    // Para que tocar el aviso abra ESE chat: la pantalla necesita
                    // saber con quien se habla, no solo el numero del chat.
                    'remitente_id'     => $sender->user_id,
                    'remitente_nombre' => (string) ($sender->name ?? ''),
                ],
                titulo: (string) ($sender->name ?: 'Mensaje nuevo'),
            );

            return response()->json([
                'message' => 'Mensaje enviado',
                'chat_id' => $chat->chat_id,
                'data' => $message->load('user')
            ], 201);
        });
    }

    public function getMessages(Request $request)
    {
        // si viene chat_id se comporta igual que antes
        if ($request->filled('chat_id')) {
            $request->validate([
                'chat_id' => 'required|exists:chats,chat_id',
            ]);

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
            if ($chat && !$this->participaEnElChat($request, $chat)) {
                return response()->json(['message' => 'Esa conversacion no es tuya.'], 403);
            }

            if (!$chat) {
                return response()->json([
                    'chat_id' => $request->chat_id,
                    'messages' => [],
                    'participants' => [],
                ]);
            }

            return $this->formatChatResponse($chat);
        }

        // 🔹 si NO viene chat_id, validar que lleguen dos user_id
        $request->validate([
            'user_id' => 'required|exists:user,user_id',
            'recipient_id' => 'required|exists:user,user_id',
        ]);

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

        if (!$chat) {
            // no hay chat creado todavía, devolver vacío
            return response()->json([
                'chat_id' => null,
                'messages' => [],
                'participants' => [],
            ]);
        }

        return $this->formatChatResponse($chat);
    }

    public function updateMessage(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:messages,message_id',
            'content' => 'required|array',
        ]);

        $message = Message::findOrFail($request->message_id);

        // Editar el mensaje de otro. Solo su autor.
        if ($no = $this->negarCuentaAjena($request, $message->user_id)) {
            return $no;
        }

        $message->content = json_encode($request->content);
        $message->save();

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }
    /**
     * Arma el JSON de salida para un chat dado
     */
    protected function formatChatResponse(Chat $chat)
    {
        return response()->json([
            'chat_id' => $chat->chat_id,
            'type' => $chat->type,
            'created_at' => $chat->created_at,
            'participants' => $chat->participants->map(function ($participant) {
                return [
                    'user_id' => $participant->user->user_id,
                    'name' => $participant->user->name,
                    'role_id' => $participant->role_id,
                    'joined_at' => $participant->joined_at,
                ];
            }),
            'messages' => $chat->messages->map(function ($message) {
                return [
                    'message_id' => $message->message_id,
                    'user_id' => $message->user->user_id,
                    'name' => $message->user->name,
                    'role_id' => $message->role_id,
                    'content' => $message->content,
                    'created_at' => $message->created_at,
                ];
            }),
        ]);
    }

    public function getUserChats(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:user,user_id',
        ]);

        // Se precarga el último mensaje de cada chat: sin él la lista solo
        // podía mostrar el nombre del contacto, sin vista previa ni fecha.
        $chats = Chat::whereHas('participants', function ($q) use ($request) {
            $q->where('user_id', $request->user_id);
        })
            ->with([
                'participants.user',
                'messages' => fn ($q) => $q->latest('created_at')->limit(1),
            ])
            ->get()
            // Los chats con actividad reciente van primero; los que no tienen
            // ningún mensaje quedan al final.
            ->sortByDesc(fn ($chat) => optional($chat->messages->first())->created_at)
            ->values();

        $formattedChats = $chats->map(function ($chat) {
            $ultimo = $chat->messages->first();

            return [
                'chat_id' => $chat->chat_id,
                'type' => $chat->type,
                'created_at' => $chat->created_at,
                'last_message' => $ultimo?->content,
                'last_message_at' => $ultimo?->created_at,
                'last_message_user_id' => $ultimo?->user_id,
                'participants' => $chat->participants->map(function ($participant) {
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
                }),
            ];
        });

        return response()->json($formattedChats);
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

        if (!$user) {
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
