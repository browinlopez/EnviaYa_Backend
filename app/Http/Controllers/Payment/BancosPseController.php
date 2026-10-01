<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Services\BoldService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * LOS BANCOS QUE ADMITEN PSE.
 *
 * Sin esta lista no hay PSE: el cobro exige `bank_code` y `bank_name`, y el
 * cliente tiene que elegir el suyo ANTES de que se abra nada. Escribirla a
 * mano no vale —son 51 entidades y se mueven: entran billeteras, se fusionan
 * bancos—, y una lista vieja no falla al mandarla, falla cuando la persona ya
 * está en la pasarela y su banco no aparece.
 *
 * Se guarda una hora en caché. No por ahorrar: es que la pide CADA comprador
 * al abrir la pantalla de pago, y si Bold tarda, la pantalla tarda.
 */
class BancosPseController extends Controller
{
    /** Una hora: los bancos no cambian en el rato de un pedido. */
    private const MINUTOS_EN_CACHE = 60;

    public function index(BoldService $bold)
    {
        try {
            $bancos = Cache::remember(
                'bold.bancos_pse',
                now()->addMinutes(self::MINUTOS_EN_CACHE),
                fn () => $this->normalizar($bold->bancosPse()),
            );

            return response()->json(['data' => $bancos]);
        } catch (\Throwable $e) {
            /*
             * Si Bold no responde, se dice. Devolver una lista vacía haría que
             * la pantalla enseñara un selector sin bancos, y eso se lee como
             * «no hay ninguno» en vez de «no pudimos preguntarle a Bold».
             */
            Log::error('No se pudo traer los bancos de PSE', [
                'excepcion' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No pudimos cargar los bancos ahora mismo. Intenta en un momento.',
            ], 503);
        }
    }

    /**
     * Fuera el banco de mentira y en orden alfabético.
     *
     * Bold mete en la lista una entrada con código 0 —«A continuación
     * seleccione su banco»— que es el texto de su propio selector, no un
     * banco. Mandarla tal cual pondría esa frase como una opción más, y quien
     * la eligiera se iría a la pasarela con `bank_code: 0`.
     */
    private function normalizar(array $respuesta): array
    {
        $bancos = $respuesta['banks'] ?? $respuesta;

        $limpios = collect($bancos)
            ->filter(fn ($b) => (int) ($b['bank_code'] ?? 0) > 0)
            ->map(fn ($b) => [
                'bank_code' => (int) $b['bank_code'],
                'bank_name' => trim((string) $b['bank_name']),
            ])
            ->sortBy('bank_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $limpios->all();
    }
}
