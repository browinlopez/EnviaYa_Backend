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
}

