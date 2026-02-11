<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Foundation\Auth\EmailVerificationRequest;

class EmailVerificationController
{
    public function verify(EmailVerificationRequest $request)
    {
        $request->fulfill();

        return redirect('https://vecipaya.com/verificado');
    }
}
