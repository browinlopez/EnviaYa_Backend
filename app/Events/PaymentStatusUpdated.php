<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $orderId;
    public $paymentState;
    public $paymentStatus;

    /**
     * @param int|string $orderId
     * @param string $paymentState estado de la orden (paid/failed/pending_online)
     * @param string $paymentStatus estado del pago en sí (approved/rejected/pending)
     */
    public function __construct($orderId, string $paymentState, string $paymentStatus)
    {
        $this->orderId = $orderId;
        $this->paymentState = $paymentState;
        $this->paymentStatus = $paymentStatus;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.' . $this->orderId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->orderId,
            'payment_state' => $this->paymentState,
            'payment_status' => strtoupper($this->paymentStatus) === 'APPROVED' ? 'APPROVED' : 'REJECTED',
        ];
    }
}
