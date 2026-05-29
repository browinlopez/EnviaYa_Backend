<?php

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Public payment webhooks
Route::post('/webhooks/{gateway}', [WebhookController::class, 'handle']);


// v1 Bold endpoints
Route::get('/payment-intent/{referenceId}', [PaymentController::class, 'getPaymentIntent']);
Route::put('/payment-intent', [PaymentController::class, 'updatePaymentIntent']);
Route::get('/payment/pse/banks', [PaymentController::class, 'getPseBanks']);
Route::get('/payment/refund/{transactionId}', [PaymentController::class, 'getRefundStatus']);
Route::get('/payment/status/', [PaymentController::class, 'getRawPaymentStatus']);
Route::get('/payment/{referenceId}', [PaymentController::class, 'getPaymentAttempt']);
Route::post('/payment/void', [PaymentController::class, 'voidPayment']);
Route::post('/payment/refund', [PaymentController::class, 'refundPayment']);
Route::post('/order/{id}/retry-payment', [PaymentController::class, 'retryPayment']);
