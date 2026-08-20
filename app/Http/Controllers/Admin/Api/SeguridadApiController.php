<?php

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SEGURIDAD DE LA CUENTA PROPIA
 *
 * Segundo factor y sesiones abiertas. Todo lo de acá es sobre UNO MISMO: no hay
 * forma de activarle ni de quitarle el segundo factor a otra persona, ni de ver
 * sus sesiones. Es deliberado — quien administra accesos reparte áreas, no toca
 * los factores de autenticación ajenos.
 *
 * Por eso ninguna ruta lleva `modulo:`: no es una sección del panel, es la
 * cuenta de quien está entrando. Basta con estar autenticado.
 */
class SeguridadApiController extends Controller
{
    /** Cuántos códigos de recuperación se entregan. */
    private const CODIGOS_RECUPERACION = 8;

    public function estado(Request $request)
    {
        $u = $request->user();

        return response()->json([
            'two_factor' => [
                'enabled'   => $u->tieneSegundoFactor(),
                // Un secreto generado y sin confirmar: quedó a medias y hay que
                // decirlo, o la pantalla ofrecería "activar" sobre algo que ya
                // está empezado.
                'pending'   => $u->two_factor_secret !== null && $u->two_factor_confirmed_at === null,
                'confirmed_at' => $u->two_factor_confirmed_at,
                'recovery_left' => count($u->two_factor_recovery_codes ?? []),
            ],
            'sessions' => $this->sesiones($request),
        ]);
    }

    /* ==================================================================
       SEGUNDO FACTOR
       ================================================================== */

    /**
     * Paso 1: genera el secreto y devuelve lo que hace falta para escanearlo.
     *
     * NO lo activa. Entre generar el secreto y demostrar que la app lo tiene hay
     * un paso, y activarlo antes de esa prueba deja fuera a quien escaneó mal —
     * sin segundo factor y sin poder entrar, que es el peor resultado posible.
     */
    public function iniciarDosFactores(Request $request)
    {
        $u = $request->user();

        if ($u->tieneSegundoFactor()) {
            return response()->json([
                'message' => 'Ya tienes el segundo factor activo. Desactívalo antes de volver a darlo de alta.',
            ], 422);
        }

        $secreto = Totp::secreto();

        $u->forceFill([
            'two_factor_secret'       => $secreto,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secreto,
            'uri'    => Totp::uri($secreto, $u->email, config('app.name', "VeciPa'Ya")),
            'message' => 'Escanea el código con tu aplicación y escribe los seis dígitos para confirmar.',
        ]);
    }

    /**
     * Paso 2: se comprueba que la app genera los códigos correctos y se activa.
     *
     * Solo aquí se entregan los códigos de recuperación, y solo una vez: quedan
     * cifrados y con resumen, así que no hay forma de volver a mostrarlos. Un
     * teléfono se pierde, se rompe o se cambia; sin ellos, el segundo factor
     * convierte cada teléfono perdido en una cuenta perdida.
     */
    public function confirmarDosFactores(Request $request)
    {
        $datos = $request->validate(['code' => 'required|string']);

        $u = $request->user();

        if (!$u->two_factor_secret) {
            return response()->json([
                'message' => 'Primero hay que dar de alta el segundo factor.',
            ], 422);
        }

        if (!Totp::verificar($u->two_factor_secret, $datos['code'])) {
            return response()->json([
                'message' => 'El código no coincide. Revisa que la hora del teléfono esté al día.',
            ], 422);
        }

        $codigos = $this->generarCodigos();

        $u->forceFill([
            'two_factor_confirmed_at'   => now(),
            // Se guardan con resumen: un código de recuperación es tan bueno
            // como una contraseña, y guardarlos en claro sería guardar ocho
            // contraseñas de la misma cuenta.
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codigos),
        ])->save();

        return response()->json([
            'message'        => 'Segundo factor activado.',
            'recovery_codes' => $codigos,
            'warning'        => 'Guárdalos ahora: no se vuelven a mostrar. Cada uno sirve una sola vez, para entrar si pierdes el teléfono.',
        ]);
    }

    /**
     * Desactivar EXIGE la contraseña.
     *
     * Sin ella, a quien deje la sesión abierta un minuto le bastaría con abrir
     * esta pantalla para quitarle el segundo factor a la cuenta, que es
     * exactamente el ataque del que protege.
     */
    public function desactivarDosFactores(Request $request)
    {
        $datos = $request->validate(['password' => 'required|string']);

        $u = $request->user();

        if (!Hash::check($datos['password'], $u->password)) {
            return response()->json(['message' => 'La contraseña no es correcta.'], 422);
        }

        $u->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        return response()->json(['message' => 'Segundo factor desactivado.']);
    }

    /** Códigos nuevos: los anteriores dejan de valer en el acto. */
    public function regenerarCodigos(Request $request)
    {
        $u = $request->user();

        if (!$u->tieneSegundoFactor()) {
            return response()->json([
                'message' => 'No tienes el segundo factor activo.',
            ], 422);
        }

        $codigos = $this->generarCodigos();

        $u->forceFill([
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codigos),
        ])->save();

        return response()->json([
            'message'        => 'Códigos nuevos. Los anteriores ya no sirven.',
            'recovery_codes' => $codigos,
        ]);
    }

    /**
     * Ocho códigos de diez caracteres, sin las letras que se confunden.
     *
     * Se copian a mano de una pantalla a un papel, así que se quitan I, L, O, 0
     * y 1: un código apuntado mal no se descubre hasta el día que hace falta.
     */
    private function generarCodigos(): array
    {
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $codigos = [];

        for ($i = 0; $i < self::CODIGOS_RECUPERACION; $i++) {
            $codigo = '';

            for ($j = 0; $j < 10; $j++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }

            // Partido en dos mitades: diez caracteres seguidos se copian peor.
            $codigos[] = substr($codigo, 0, 5) . '-' . substr($codigo, 5);
        }

        return $codigos;
    }

    /* ==================================================================
       SESIONES
       ================================================================== */

    /**
     * Las sesiones abiertas de esta cuenta.
     *
     * Existe para una sola cosa: reconocer la que no es tuya y cerrarla. Por eso
     * lleva de dónde salió y cuándo se usó por última vez — una lista de
     * "token #3, token #7" no sirve para decidir nada.
     */
    public function sesiones(Request $request): array
    {
        $actual = $request->user()->currentAccessToken();

        return $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'ip'           => $t->ip,
                'device'       => $this->dispositivo($t->user_agent),
                'created_at'   => $t->created_at,
                'last_used_at' => $t->last_used_at,
                // Marcar la propia es imprescindible: sin eso, cerrar "todas las
                // demás" es un salto de fe.
                'current'      => $actual && (int) $actual->id === (int) $t->id,
            ])
            ->all();
    }

    public function cerrarSesion(Request $request, $id)
    {
        $actual = $request->user()->currentAccessToken();

        if ($actual && (int) $actual->id === (int) $id) {
            return response()->json([
                'message' => 'Esa es la sesión desde la que estás trabajando. Usa "Cerrar sesión" del menú.',
            ], 422);
        }

        $borrados = $request->user()->tokens()->where('id', $id)->delete();

        abort_if(!$borrados, 404, 'Esa sesión ya no existe.');

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Cierra TODAS menos la actual.
     *
     * Es lo primero que hay que poder hacer cuando se sospecha que una
     * contraseña se filtró, y hacerlo sesión por sesión es justo lo que nadie
     * hace con prisa.
     */
    public function cerrarLasDemas(Request $request)
    {
        $actual = $request->user()->currentAccessToken();

        $cerradas = $request->user()->tokens()
            ->when($actual, fn ($q) => $q->where('id', '!=', $actual->id))
            ->delete();

        return response()->json([
            'message' => $cerradas === 0
                ? 'No había otras sesiones abiertas.'
                : "Se cerraron {$cerradas} sesión(es). La tuya sigue abierta.",
            'closed' => $cerradas,
        ]);
    }

    /**
     * "Chrome en Windows" a partir del agente de usuario.
     *
     * Aproximado a propósito: no se trata de identificar el aparato, sino de que
     * quien mira la lista reconozca cuál es el suyo y cuál no.
     */
    private function dispositivo(?string $agente): string
    {
        if (!$agente) {
            return 'Desconocido';
        }

        $navegador = match (true) {
            str_contains($agente, 'Edg/')     => 'Edge',
            str_contains($agente, 'OPR/')     => 'Opera',
            str_contains($agente, 'Chrome')   => 'Chrome',
            str_contains($agente, 'Firefox')  => 'Firefox',
            str_contains($agente, 'Safari')   => 'Safari',
            default => 'Navegador',
        };

        $sistema = match (true) {
            str_contains($agente, 'Windows')  => 'Windows',
            str_contains($agente, 'Android')  => 'Android',
            str_contains($agente, 'iPhone'), str_contains($agente, 'iPad') => 'iOS',
            str_contains($agente, 'Mac OS')   => 'macOS',
            str_contains($agente, 'Linux')    => 'Linux',
            default => null,
        };

        return $sistema ? "{$navegador} en {$sistema}" : $navegador;
    }
}
