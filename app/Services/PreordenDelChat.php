<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\Order\OrdersSales;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

// La preorden vive en la tarjeta del chat: estas son sus reglas, en un solo sitio.
class PreordenDelChat
{
    public const PENDIENTE = 'pending';

    public const CONFIRMADA = 'confirmed';

    public const CANCELADA = 'cancelled';

    public const PAGADA = 'paid';

    public function __construct(private readonly NegocioDelUsuario $negocios) {}

    /** @return array<string, mixed>|null */
    public static function tarjeta(?string $contenido): ?array
    {
        $texto = trim((string) $contenido);
        if (! str_starts_with($texto, '{')) {
            return null;
        }
        $datos = json_decode($texto, true);

        return is_array($datos) && ($datos['type'] ?? null) === 'preorder' ? $datos : null;
    }

    /** @param  array<string, mixed>  $t */
    public static function deQuien(array $t): string
    {
        // Las tarjetas viejas no dicen de quién son: todas las armaba el negocio.
        return ($t['de'] ?? 'negocio') === 'cliente' ? 'cliente' : 'negocio';
    }

    /** @param  array<int, array<string, mixed>>  $items */
    public static function total(array $items): float
    {
        return round(array_sum(array_map(fn (array $i) => self::importe($i), $items)), 2);
    }

    /** @param  array<string, mixed>  $i */
    public static function importe(array $i): float
    {
        $precio = (float) ($i['price'] ?? 0);
        if (($i['pedido_por'] ?? null) === 'valor') {
            return (float) ($i['requested_value'] ?? 0);
        }
        if (($i['pedido_por'] ?? null) === 'cantidad' && isset($i['requested_qty'])) {
            return $precio * (float) $i['requested_qty'];
        }

        return $precio * (float) ($i['quantity'] ?? 0);
    }

    /** @param  array<string, mixed>  $t */
    public function puedeMandar(User $quien, ?int $negocioId, array $t): bool
    {
        if ($negocioId === null) {
            return false;
        }

        return self::deQuien($t) === 'cliente'
            ? (int) $quien->rol === 1
            : $this->negocios->administra((int) $quien->user_id, $negocioId);
    }

    /**
     * @param  array<string, mixed>  $despues
     * @return array<string, mixed>|string La tarjeta nueva, o el motivo del rechazo.
     */
    public function responder(Message $m, int $yo, array $despues): array|string
    {
        $antes = self::tarjeta((string) $m->content);
        if ($antes === null) {
            return 'no_es_preorden';
        }
        if (! ChatParticipant::where('chat_id', $m->chat_id)->where('user_id', $yo)->exists()) {
            return 'ajena';
        }
        if (($antes['status'] ?? self::PENDIENTE) !== self::PENDIENTE) {
            return 'cerrada';
        }

        $estado = $despues['status'] ?? null;
        if (! in_array($estado, [self::CONFIRMADA, self::CANCELADA], true)) {
            return 'estado_invalido';
        }

        $negocioId = Chat::whereKey($m->chat_id)->value('busines_id');
        $esDelNegocio = $negocioId !== null && $this->negocios->administra($yo, (int) $negocioId);

        if (self::deQuien($antes) === 'negocio') {
            // La del negocio la contesta el cliente, y solo cambia el estado.
            return $esDelNegocio || ! $this->igualSinEstado($antes, $despues)
                ? 'no_permitido'
                : [...$antes, 'status' => $estado];
        }

        if ((int) $m->user_id === $yo) {
            // Quien la armó solo puede retirarla mientras espera.
            return $estado === self::CANCELADA && $this->igualSinEstado($antes, $despues)
                ? [...$antes, 'status' => $estado]
                : 'no_permitido';
        }

        if (! $esDelNegocio) {
            return 'no_permitido';
        }

        $items = $this->ajustar($antes['items'] ?? [], $despues['items'] ?? $antes['items'] ?? []);
        if (is_string($items)) {
            return $items;
        }

        return [
            ...$antes,
            'status' => $estado,
            'items' => $items,
            'total' => self::total($items),
            'ajustada' => $items != ($antes['items'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $antes
     * @param  array<int, array<string, mixed>>  $despues
     * @return array<int, array<string, mixed>>|string
     */
    private function ajustar(array $antes, array $despues): array|string
    {
        $pedidas = collect($antes)->keyBy(fn ($i) => (int) ($i['product_id'] ?? 0));
        $quedan = [];

        foreach ($despues as $i) {
            $original = $pedidas->get((int) ($i['product_id'] ?? 0));
            // Solo lo que el cliente pidió: el negocio no le agrega cosas que no pidió.
            if ($original === null) {
                return 'producto_no_pedido';
            }
            $cantidad = (float) ($i['quantity'] ?? 0);
            if ($cantidad <= 0 || $cantidad > (float) ($original['quantity'] ?? 0)) {
                return 'cantidad_invalida';
            }
            $precio = (float) ($i['price'] ?? 0);
            if ($precio <= 0) {
                return 'precio_invalido';
            }
            $quedan[] = [...$original, 'quantity' => $i['quantity'], 'price' => $precio];
        }

        return $quedan === [] ? 'sin_productos' : $quedan;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function igualSinEstado(array $a, array $b): bool
    {
        $sin = fn (array $c) => array_diff_key($c, ['status' => true]);

        return $sin($a) == $sin($b);
    }

    /**
     * @param  array<int, array<string, mixed>>  $productos
     * @return array{0: Message, 1: array<string, mixed>}|string
     */
    public function paraPagar(int $messageId, int $compradorId, int $negocioId, array $productos): array|string
    {
        $m = Message::whereKey($messageId)->lockForUpdate()->first();
        $t = $m ? self::tarjeta((string) $m->content) : null;
        if ($m === null || $t === null) {
            return 'preorden_ajena';
        }

        $chat = Chat::whereKey($m->chat_id)->first();
        $esSuya = $chat && (int) $chat->busines_id === $negocioId
            && ChatParticipant::where('chat_id', $chat->chat_id)->where('user_id', $compradorId)->exists();
        if (! $esSuya) {
            return 'preorden_ajena';
        }

        $estado = $t['status'] ?? self::PENDIENTE;
        // Un cobro en línea a medias se reintenta sobre el mismo pedido: eso no es pagar dos veces.
        $pedido = isset($t['order_id']) ? OrdersSales::find((int) $t['order_id']) : null;
        if ($estado === self::PAGADA && ! $pedido?->esperandoPago()) {
            return 'preorden_pagada';
        }
        if ($estado === self::PAGADA) {
            return [$m, $t];
        }
        if ($estado !== self::CONFIRMADA) {
            return 'preorden_sin_confirmar';
        }

        // Se paga exactamente lo confirmado: ni más productos ni más cantidad.
        $confirmadas = collect($t['items'] ?? [])->mapWithKeys(fn ($i) => [(int) $i['product_id'] => (int) ceil((float) $i['quantity'])]);
        $pide = collect($productos)->mapWithKeys(fn ($p) => [(int) $p['product_id'] => (int) $p['amount']]);
        if ($confirmadas->sortKeys()->all() !== $pide->sortKeys()->all()) {
            return 'preorden_distinta';
        }

        return [$m, $t];
    }

    /**
     * @param  Collection<int|string, mixed>  $precios
     * @param  array<string, mixed>  $t
     * @return Collection<int|string, mixed>
     */
    public static function conPreciosPactados(Collection $precios, array $t): Collection
    {
        $pactados = clone $precios;
        foreach ($t['items'] ?? [] as $i) {
            $id = (int) ($i['product_id'] ?? 0);
            // Solo lo que el negocio sí vende: un precio pactado no revive un producto retirado.
            if ($pactados->has($id) && (float) ($i['price'] ?? 0) > 0) {
                $pactados->put($id, (float) $i['price']);
            }
        }

        return $pactados;
    }

    public static function respuestaQueNoSePaga(string $motivo, int $messageId): JsonResponse
    {
        $mensajes = [
            'preorden_sin_confirmar' => 'El negocio todavía no confirma tu pedido. Espéralo en el chat.',
            'preorden_pagada' => 'Ese pedido ya se pagó.',
            'preorden_distinta' => 'Lo que vas a pagar no es lo que confirmó el negocio.',
        ];

        return response()->json([
            'message' => $mensajes[$motivo] ?? 'Ese pedido no es tuyo.',
            'reason' => $motivo,
            'chat_id' => Message::whereKey($messageId)->value('chat_id'),
        ], 409);
    }

    /** @param  array<string, mixed>  $t */
    public function marcarPagada(Message $m, array $t, int $orderId): void
    {
        $m->content = json_encode([...$t, 'status' => self::PAGADA, 'order_id' => $orderId]);
        $m->save();
    }
}
