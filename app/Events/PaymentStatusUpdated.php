<?php

namespace App\Events;

use App\Models\Payment\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Payment $payment)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('order.' . $this->payment->orderSales_id);
    }

    public function broadcastAs(): string
    {
        return 'payment.updated';
    }

    public function broadcastWith(): array
    {
        // 'payment_status' usa el mismo vocabulario que ya devuelve
        // PaymentController::checkStatus (APPROVED/REJECTED), para que el
        // front trate igual un evento por socket que uno de polling.
        return [
            'orderSales_id' => $this->payment->orderSales_id,
            'status' => $this->payment->status,
            'payment_status' => $this->payment->payment_status === 1 ? 'APPROVED' : 'REJECTED',
        ];
    }
}
