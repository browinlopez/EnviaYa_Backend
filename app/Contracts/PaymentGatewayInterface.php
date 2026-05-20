<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    public function createIntent(array $body): array;
    public function makePayment(array $body): array;
    public function checkPayment(string $reference): array;
    public function handleWebhook(array $payload, array $headers): array;

    public function initiatePaymentFlow(\App\Models\OrderSale $order, array $data): array;
    public function checkPaymentStatusFlow(string $reference): array;

    public function getPaymentIntent(string $referenceId): array;
    public function updatePaymentIntent(array $body): array;
    public function getPaymentAttempt(string $referenceId): array;
    public function getPseBanks(): array;
    public function voidPayment(array $body): array;
    public function refundPayment(array $body): array;
    public function getRefundStatus(string $transactionId): array;
}

