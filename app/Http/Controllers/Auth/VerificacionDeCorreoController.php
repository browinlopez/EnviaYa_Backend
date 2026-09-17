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

class VerificacionDeCorreoController extends Controller
{
    use AyudasDeAcceso;

    public function verify(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $user = User::where('email_verification_token', $request->token)->first();

        if (!$user) {
            /*
             * Se llega aca sobre todo por enlaces VIEJOS: quien pidio el
             * reenvio dos veces tiene dos correos, y el primero ya no sirve.
             * Decirlo asi evita que la persona crea que su cuenta esta rota.
             */
            return view('auth.verify-error', [
                'message' => 'Este enlace no es válido. Puede que sea de un correo anterior: '
                    . 'busca el más reciente en tu bandeja.',
            ]);
        }

        if ($user->email_verified_at) {
            return view('auth.verify-success');
        }

        if (
            !$user->email_verification_expires_at ||
            $user->email_verification_expires_at->isPast()
        ) {
            /*
             * Caducado no es lo mismo que invalido, y la salida es distinta:
             * la cuenta existe y solo hace falta otro enlace. Se pasa el correo
             * a la vista para que pueda ofrecer el reenvio sin volver a
             * preguntarlo.
             */
            return view('auth.verify-error', [
                'message' => 'Este enlace ya caducó. Los enlaces duran una hora.',
                'email'   => $user->email,
                'caducado' => true,
            ]);
        }

        /*
         * EL TOKEN NO SE BORRA.
         *
         * Se ponia en `null` al verificar, y eso hacia que la SEGUNDA visita
         * al mismo enlace no encontrara a nadie: «Token invalido», sobre una
         * cuenta que acababa de quedar verificada. Y una segunda visita pasa
         * todo el tiempo —el antivirus del correo abre los enlaces antes que
         * la persona, Gmail los escanea, alguien pulsa dos veces o refresca—
         * asi que lo normal era ver el error justo despues del exito.
         *
         * Conservarlo no reabre nada: la comprobacion de `email_verified_at`
         * de mas arriba corta antes y responde que ya esta lista. El token
         * queda inerte, y ademas caduca solo.
         */
        $user->update([
            'email_verified_at' => Carbon::now(),
            'email_verification_expires_at' => null,
        ]);

        return view('auth.verify-success');
    }

    public function resendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // 🔒 Respuesta genérica (evita enumeración de usuarios)
        if (!$user) {
            return response()->json([
                'message' => 'Si el correo existe, se enviará un enlace de verificación.'
            ], 200);
        }

        // ✅ Ya verificado
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'El correo ya está verificado.'
            ], 200);
        }

        $this->renovarEnlaceSiHaceFalta($user);

        /*
         * EL MISMO CUIDADO QUE EN EL REGISTRO.
         *
         * Esto enviaba sin proteger, asi que un SMTP caido daba un 500. Y este
         * es JUSTO el endpoint al que se llega cuando el correo no llego: la
         * persona que no puede entrar pulsa «reenviar» y recibe otro error, sin
         * saber si el problema es suyo o del servidor.
         *
         * Comprobado contra produccion: respondia 500 en cinco segundos.
         */
        try {
            $this->sendVerificationEmail($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('No se pudo reenviar la verificacion', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            /*
             * 503 y no 500: el problema es del servicio de correo, no de lo que
             * pidio la persona, y el cliente puede distinguirlo para ofrecer
             * «intentalo mas tarde» en vez de «algo salio mal».
             */
            return response()->json([
                'message' => 'No pudimos enviar el correo en este momento. Intentalo en unos minutos.',
                'email_enviado' => false,
            ], 503);
        }

        return response()->json([
            'message' => 'Si el correo existe, se ha enviado el enlace de verificación.',
            'email_enviado' => true,
        ], 200);
    }

    /**
     * Pedir un enlace nuevo desde el navegador.
     *
     * La página de «no pudimos verificar tu correo» solo ofrecía volver al
     * sitio o escribir a soporte: quien abría un enlace vencido o roto —los
     * que salieron con el dominio viejo, por ejemplo— tenía que adivinar que
     * debía ir a la app, intentar entrar y esperar a que le ofreciera
     * reenviar. Ahora lo pide ahí mismo, con su correo.
     *
     * La respuesta es la misma exista o no la cuenta, y esté o no verificada:
     * una página que dijera «ese correo no está registrado» serviría para
     * averiguar quién tiene cuenta probando direcciones.
     */
    public function reenviarDesdeLaWeb(Request $request)
    {
        $datos = $request->validate(['email' => 'required|email|max:255']);

        $user = User::where('email', mb_strtolower(trim($datos['email'])))->first();

        if ($user && !$user->email_verified_at) {
            $this->renovarEnlaceSiHaceFalta($user);

            try {
                $this->sendVerificationEmail($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('No se pudo reenviar la verificacion desde la web', [
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);

                return view('auth.verify-error', [
                    'message' => 'No pudimos enviar el correo en este momento. Inténtalo en unos minutos.',
                    'email'   => $user->email,
                ]);
            }
        }

        return view('auth.verify-reenviado', ['email' => $datos['email']]);
    }

    /**
     * Un enlace vigente se reutiliza y uno vencido se cambia por otro.
     *
     * Reutilizarlo es a propósito: si alguien pide dos correos seguidos, el
     * primero que abra tiene que servirle, no decirle «este enlace no es
     * válido» porque el segundo lo reemplazó.
     */
    private function renovarEnlaceSiHaceFalta(User $user): void
    {
        if (
            !$user->email_verification_token ||
            !$user->email_verification_expires_at ||
            $user->email_verification_expires_at->isPast()
        ) {
            $user->update([
                'email_verification_token' => Str::random(60),
                'email_verification_expires_at' => now()->addMinutes(60),
            ]);
        }
    }
}
