<?php

namespace App\Http\Controllers\Chat;

use App\Events\MessageSent;
use App\Helper\ReverbClient;
use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
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

            // -----------------------------
            // Usar ReverbClient para enviar mensaje
            // -----------------------------
            // Disparar evento para broadcasting
            /* event(new MessageSent($message)); */

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
            'user_id' => 'required|exists:users,user_id',
            'recipient_id' => 'required|exists:users,user_id',
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

        $chats = Chat::whereHas('participants', function ($q) use ($request) {
            $q->where('user_id', $request->user_id);
        })->with('participants.user')->get();

        $formattedChats = $chats->map(function ($chat) {
            return [
                'chat_id' => $chat->chat_id,
                'type' => $chat->type,
                'created_at' => $chat->created_at,
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
}
