<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use App\Models\OrderSale;
use App\Models\PaymentIntent;
use App\Models\PaymentGateway;

class PaymentService
{
    /**
     * Resuelve dinámicamente el servicio de la pasarela de pago solicitada desde la base de datos.
     */
    protected function getGateway(string $name): PaymentGatewayInterface
    {
        $gatewayRecord = PaymentGateway::where('name', strtolower($name))
            ->where('state', true)
            ->first();

        if (!$gatewayRecord) {
            throw new \Exception("La pasarela de pago '{$name}' no está registrada o no está activa.");
        }

        $class = "App\\Services\\" . $gatewayRecord->class;

        if (!class_exists($class)) {
            throw new \Exception("El servicio de pasarela de pago '{$class}' no existe.");
        }

        return app($class);
    }

    /**
     * Inicia el flujo completo de pago delegando en la pasarela seleccionada.
     */
    public function initiatePayment(OrderSale $order, array $data): array
    {
        $gatewayName = $data['payment_gateway'] ?? 'bold';
        $gateway = $this->getGateway($gatewayName);

        return $gateway->initiatePaymentFlow($order, $data);
    }

    /**
     * Reintenta el pago de una orden existente delegando en la pasarela seleccionada.
     */
    public function retryPayment(OrderSale $order, array $data): array
    {
        // 2. Validar que la orden NO esté en estado PAID
        if (strtolower($order->payment_state) === 'paid') {
            throw new \Exception("La orden ya se encuentra en estado de pago exitoso (PAID) y no puede ser reintentada.");
        }

        // 3. Validar que la pasarela exista y esté activa (resuelto por getGateway)
        $gatewayName = $data['payment_gateway'] ?? 'bold';
        $gateway = $this->getGateway($gatewayName);

        return $gateway->retryPaymentFlow($order, $data);
    }

    /**
     * Consulta el estado del pago delegando en la pasarela correspondiente.
     */
    public function checkPaymentStatus(string $reference): array
    {
        $intent = PaymentIntent::where('bold_reference_id', $reference)->firstOrFail();
        $gateway = $this->getGateway($intent->provider ?? 'bold');

        return $gateway->checkPaymentStatusFlow($reference);
    }

    public function getPaymentIntent(string $referenceId): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->getPaymentIntent($referenceId);
    }

    public function updatePaymentIntent(array $body): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->updatePaymentIntent($body);
    }

    public function getPaymentAttempt(string $referenceId): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->getPaymentAttempt($referenceId);
    }

    public function getPseBanks(): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->getPseBanks();
    }

    public function voidPayment(array $body): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->voidPayment($body);
    }

    public function refundPayment(array $body): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->refundPayment($body);
    }

    public function getRefundStatus(string $transactionId): array
    {
        $gateway = $this->getGateway('bold');
        return $gateway->getRefundStatus($transactionId);
    }

    public function getGatewayById(int $id): PaymentGatewayInterface
    {
        $gatewayRecord = PaymentGateway::where('id', $id)
            ->where('state', true)
            ->first();

        if (!$gatewayRecord) {
            throw new \Exception("La pasarela de pago seleccionada no existe o se encuentra inactiva.");
        }

        $class = "App\\Services\\" . $gatewayRecord->class;

        if (!class_exists($class)) {
            throw new \Exception("El servicio de pasarela de pago '{$class}' no existe.");
        }

        return app($class);
    }

    public function getRawPaymentStatus(string $referenceId, int $gatewayId)
    {
        $gateway = $this->getGatewayById($gatewayId);
        return $gateway->getRawPaymentStatus($referenceId);
    }
}

