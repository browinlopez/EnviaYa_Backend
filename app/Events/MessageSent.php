<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        $this->message = $message->load('user'); // Cargar la relación del usuario
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->message->chat_id),
        ];
    }

    /**
     * Data a enviar al cliente.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->message_id,
            'chat_id' => $this->message->chat_id,
            'user_id' => $this->message->user_id,
            'name' => $this->message->user->name,
            'role_id' => $this->message->role_id,
            'content' => $this->message->content,
            'file_url' => $this->message->file_url ?? null,
            'file_type' => $this->message->file_type ?? null,
            'created_at' => $this->message->created_at,
        ];
    }
}
