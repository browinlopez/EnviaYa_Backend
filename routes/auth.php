<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

/*
 * AQUI NO HAY REGISTRO, Y ES A PROPOSITO.
 *
 * Breeze trae de fabrica `GET/POST /register`, y en este proyecto el
 * controlador creaba el usuario con `'rol' => 4` —administrador— y ademas le
 * iniciaba la sesion. `EnsureAdmin`, que es la puerta del panel interno,
 * comprueba exactamente una cosa: que el rol sea 4.
 *
 * O sea que habia un formulario publico, sin invitacion ni aprobacion, que
 * repartia el rol de administrador. No hacia falta explotar nada: se rellenaba.
 * Estuvo servido en produccion.
 *
 * Las cuentas de verdad se crean por dos caminos, los dos con su rol correcto:
 *
 *   · la app, por `POST /v1/register` (`RegistroController`);
 *   · el panel, dando de alta a alguien desde dentro y ya autenticado.
 *
 * Si algun dia hace falta un alta web, que NO sea esta: que no asigne rol por
 * defecto y que exija invitacion.
 */
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('laravel-verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('laravel-verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
