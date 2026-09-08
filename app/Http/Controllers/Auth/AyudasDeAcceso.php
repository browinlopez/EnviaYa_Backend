<?php

namespace App\Http\Controllers\Auth;

use App\Services\Totp;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User\UserAddress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use App\Mail\VerifyEmailCustomMail;

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
            . '/verify-email?token='
            . $user->email_verification_token;

        Mail::to($user->email)->send(
            new VerifyEmailCustomMail($actionUrl)
        );
    }
}
