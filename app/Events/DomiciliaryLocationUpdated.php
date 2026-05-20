<?php

namespace App\Events;

use App\Models\OrderGeolocation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DomiciliaryLocationUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $geolocation;

    public function __construct(OrderGeolocation $geolocation)
    {
        $this->geolocation = $geolocation;
    }

    // canal dinámico por pedido
    public function broadcastOn()
    {
        return new Channel('order.'.$this->geolocation->order_sale_id);
    }

    public function broadcastAs()
    {
        return 'location.updated';
    }
}
