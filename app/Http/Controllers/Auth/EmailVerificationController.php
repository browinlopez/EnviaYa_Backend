<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

class EmailVerificationController extends Controller
{
    public function __invoke(EmailVerificationRequest $request)
    {
        $request->fulfill(); // 👈 aquí se verifica el email

        return redirect('https://vecipaya.com/verificado');
    }
}

