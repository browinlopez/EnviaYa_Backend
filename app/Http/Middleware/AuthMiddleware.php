<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    /**
     * Toma el token de la cookie 'auth_token' y lo inserta como Bearer token en el Header Authorization
     * si no existe ya un Header de Authorization. Esto permite que Sanctum valide de forma transparente.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->headers->has('Authorization') && $request->hasCookie('auth_token')) {
            $token = $request->cookie('auth_token');
            if (!empty($token)) {
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        return $next($request);
    }
}
