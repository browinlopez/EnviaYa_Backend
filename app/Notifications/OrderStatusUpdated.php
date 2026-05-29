<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\OrderSale;

class OrderStatusUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public $order;
    public $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(OrderSale $order, string $message)
    {
        $this->order   = $order;
        $this->message = $message;
        // Enrutar a RabbitMQ, cola notifications
        $this->onConnection('rabbitmq')->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Envia a la DB nativa y a Reverb por WebSockets
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification para la BD.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'state' => $this->order->state,
            'message' => $this->message,
        ];
    }

    /**
     * Get the broadcast representation of the notification para Reverb.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'order_id' => $this->order->id,
            'state' => $this->order->state,
            'message' => $this->message,
            'time' => now()->toDateTimeString(),
        ]);
    }
}
