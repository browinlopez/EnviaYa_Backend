<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Enviar un mensaje en un chat
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,chat_id',
            'user_id' => 'required|exists:user,user_id',
            'role_id' => 'required|exists:rol,rol_id',
            'content' => 'required|string|max:1000',
        ]);

        return DB::transaction(function () use ($request) {
            $chat = Chat::findOrFail($request->chat_id);

            $message = Message::create([
                'chat_id' => $chat->chat_id,
                'user_id' => $request->user_id,
                'role_id' => $request->role_id,
                'content' => $request->content,
            ]);

            return response()->json([
                'message' => 'Mensaje enviado',
                'data' => $message->load('user')
            ], 201);
        });
    }

    public function getMessages(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,chat_id',
        ]);

        $chat = Chat::with(['messages.user', 'participants.user'])
            ->findOrFail($request->chat_id);

        return response()->json([
            'chat_id' => $chat->chat_id,
            'type' => $chat->type,
            'created_at' => $chat->created_at,
            'participants' => $chat->participants->map(function ($participant) {
                return [
                    'user_id' => $participant->user->user_id,
                    'name' => $participant->user->name,
                    'email' => $participant->user->email,
                    'role_id' => $participant->role_id,
                    'qualification' => $participant->user->qualification,
                    'state' => $participant->user->state,
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
