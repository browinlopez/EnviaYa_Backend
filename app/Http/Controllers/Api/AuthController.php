<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Requests\Auth\ResendVerificationEmailRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Registro público de compradores.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $this->authService->register($request->validated());

            return response()->json([
                'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.'
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json([
                    'message' => 'El correo ya está registrado'
                ], 409);
            }

            return response()->json([
                'message' => 'Error al registrar el usuario',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al registrar el usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifica el correo del usuario mediante token.
     */
    public function verify(VerifyEmailRequest $request)
    {
        $result = $this->authService->verify($request->token);

        if (!$result['success']) {
            $message = $result['error'] === 'expired' 
                ? 'El enlace de verificación ha expirado' 
                : 'Token inválido';

            return view('auth.verify-error', ['message' => $message]);
        }

        return view('auth.verify-success');
    }

    /**
     * Reenvía correo de verificación (API).
     */
    public function resendVerificationEmail(ResendVerificationEmailRequest $request): JsonResponse
    {
        $message = $this->authService->resendVerification($request->email);
        return response()->json(['message' => $message], 200);
    }

    /**
     * Inicia sesión del usuario y adjunta cookie HTTP-Only.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login($request->only('email', 'password'));

            // 🍪 CONFIGURACIÓN DE LA COOKIE:
            // Adaptado para localhost (HTTP). Se incluyen comentarios sobre cómo cambiar para producción (HTTPS).
            $cookie = cookie(
                'auth_token',                       // Nombre de la cookie (Mantener igual en prod)
                $data['token'],                     // Valor del token (Mantener igual en prod)
                1440,                               // Expiración en minutos (24 horas) (Mantener o ajustar en prod)
                '/',                                // Path de validez (Mantener igual en prod)
                null,                               // Dominio (Dejar null en local. En producción cambiar a '.tudominio.com' si quieres compartir la cookie entre subdominios, o dejar null)
                false,                              // SECURE: false para Localhost HTTP. ⚠️ CAMBIAR A true EN PRODUCCIÓN (HTTPS) ⚠️
                true,                               // HTTP_ONLY: true (impide lectura desde JS, protegiendo contra ataques XSS. Mantener true en prod)
                false,                              // RAW: false (Mantener igual en prod)
                'Lax'                               // SAME_SITE: 'Lax' para localhost. En producción, usar 'Lax' o 'None' (Nota: 'None' requiere obligatoriamente Secure = true)
            );

            return response()->json([
                'user'  => $data['user'],
                'token' => $data['token'],
            ])->withCookie($cookie);

        } catch (\Exception $e) {
            $code = $e->getCode();
            // Evitar códigos de excepción PHP no válidos para cabeceras HTTP
            $statusCode = ($code >= 400 && $code < 600) ? $code : 401;

            return response()->json([
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }

    /**
     * Cierra la sesión del usuario y limpia la cookie de autenticación.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        // 🍪 ELIMINAR LA COOKIE:
        $cookie = cookie()->forget('auth_token');

        return response()->json(['message' => 'Sesión cerrada correctamente'])->withCookie($cookie);
    }

    /**
     * Obtiene el perfil del usuario autenticado.
     */
    public function profile(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Envía link para restablecer contraseña.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $sent = $this->authService->sendResetLink($request->email);

        return $sent
            ? response()->json(['message' => 'Se envió el enlace al correo'])
            : response()->json(['message' => 'No se pudo enviar el enlace'], 500);
    }

    /**
     * Reenvía correo de verificación para Web (Blade flow).
     */
    public function resendVerificationEmailWeb(ResendVerificationEmailRequest $request)
    {
        $result = $this->authService->resendVerificationWeb($request->email);

        if ($result['status'] === 'error') {
            return back()->with('status', $result['message']);
        }

        if ($result['status'] === 'already_verified') {
            return redirect()->route('login')->with('status', $result['message']);
        }

        return back()->with('status', $result['message']);
    }
}
