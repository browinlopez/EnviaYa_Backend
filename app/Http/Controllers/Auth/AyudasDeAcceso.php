<?php

namespace App\Http\Controllers\Auth;

use App\Mail\VerifyEmailCustomMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Auxiliares que usan varios controladores del panel.
 *
 * Estaban sueltos dentro del controlador gordo. Aca no se duplican:
 * quien los necesite usa el trait.
 */
trait AyudasDeAcceso
{
    private function sendVerificationEmail(User $user)
    {
        $actionUrl = config('app.url') // 👈 BACKEND
            .'/verify-email?token='
            .$user->email_verification_token;

        Mail::to($user->email)->send(
            new VerifyEmailCustomMail($actionUrl)
        );
    }
}
