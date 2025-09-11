<?php

namespace App\Events;

use App\Models\Chat\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load('user');
    }

    // Canal privado para el chat
    public function broadcastOn()
    {
        return new PrivateChannel('chat.' . $this->message->chat_id);
    }

    public function broadcastWith()
    {
        return [
            'message_id' => $this->message->message_id,
            'chat_id' => $this->message->chat_id,
            'user_id' => $this->message->user_id,
            'name' => $this->message->user->name,
            'role_id' => $this->message->role_id,
            'content' => $this->message->content,
            'created_at' => $this->message->created_at,
        ];
    }
}
