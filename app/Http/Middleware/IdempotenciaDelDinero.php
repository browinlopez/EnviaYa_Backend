<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Si la red se cae tras cobrar, el reintento del mismo intento no anota la plata otra vez. */
class IdempotenciaDelDinero
{
    public const CABECERA = 'Idempotency-Key';

    public const HORAS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $clave = trim((string) $request->header(self::CABECERA, ''));
        $usuario = (int) ($request->user()?->getAuthIdentifier() ?? 0);

        // Sin clave pasa como siempre: una app vieja no se queda por fuera.
        if ($clave === '' || $usuario === 0) {
            return $next($request);
        }

        if (! Str::isUuid($clave)) {
            return response()->json([
                'message' => 'La clave del intento no es válida.',
                'errors' => ['idempotency_key' => ['Idempotency-Key tiene que ser un UUID.']],
            ], 422);
        }

        $clave = strtolower($clave);
        $huella = $this->huella($request);

        IdempotencyKey::where('user_id', $usuario)
            ->where('idempotency_key', $clave)
            ->where('expires_at', '<=', now())
            ->delete();

        try {
            $reserva = IdempotencyKey::create([
                'user_id' => $usuario,
                'idempotency_key' => $clave,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'fingerprint' => $huella,
                'expires_at' => now()->addHours(self::HORAS),
            ]);
        } catch (UniqueConstraintViolationException) {
            // El índice único decide: de dos peticiones a la vez con la misma clave, entra una.
            return $this->repetida($usuario, $clave, $huella);
        }

        try {
            $respuesta = $next($request);
        } catch (Throwable $e) {
            $reserva->delete();

            throw $e;
        }

        $contenido = $this->contenidoGuardable($respuesta);

        // Un rechazo no tuvo efecto: se suelta la clave para que el reintento sí entre.
        if ($respuesta->isSuccessful() && $contenido !== null) {
            $reserva->update([
                'status_code' => $respuesta->getStatusCode(),
                'response_body' => $contenido,
                'response_content_type' => $respuesta->headers->get('Content-Type'),
            ]);
        } else {
            $reserva->delete();
        }

        return $respuesta;
    }

    private function repetida(int $usuario, string $clave, string $huella): Response
    {
        $previa = IdempotencyKey::where('user_id', $usuario)->where('idempotency_key', $clave)->first();

        if ($previa === null || $previa->status_code === null) {
            return response()->json([
                'message' => 'Esta operación todavía se está registrando. Espera un momento y revisa si quedó antes de repetirla.',
                'code' => 'IDEMPOTENCY_IN_PROGRESS',
            ], 409);
        }

        // Ejecutar una clave reusada para otra cosa escondería el error del cliente.
        if (! hash_equals($previa->fingerprint, $huella)) {
            return response()->json([
                'message' => 'Esta clave de intento ya se usó con otros datos.',
                'code' => 'IDEMPOTENCY_KEY_REUSED',
                'errors' => ['idempotency_key' => ['La clave ya se usó con otros datos.']],
            ], 422);
        }

        return response((string) $previa->response_body, $previa->status_code)
            ->header('Content-Type', $previa->response_content_type ?? 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }

    private function huella(Request $request): string
    {
        $archivos = [];

        // Un archivo cuenta por su contenido, no por el nombre temporal que le da PHP.
        foreach (Arr::dot($request->allFiles()) as $campo => $archivo) {
            if ($archivo instanceof UploadedFile) {
                $ruta = $archivo->getRealPath();
                $archivos[$campo] = $ruta !== false ? hash_file('sha256', $ruta) : null;
            }
        }

        ksort($archivos);

        return hash('sha256', (string) json_encode([
            $request->method(),
            $request->path(),
            // La misma clave en otra tienda es otro intento.
            $request->attributes->get('busines_id') ?? $request->header('X-Negocio'),
            $this->ordenado($request->input()),
            $archivos,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    // {a, b} y {b, a} son el mismo intento.
    private function ordenado(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return array_map(fn ($v) => $this->ordenado($v), $valor);
    }

    private function contenidoGuardable(Response $respuesta): ?string
    {
        if ($respuesta instanceof StreamedResponse || $respuesta instanceof BinaryFileResponse) {
            return null;
        }

        $contenido = $respuesta->getContent();

        return is_string($contenido) ? $contenido : null;
    }
}
