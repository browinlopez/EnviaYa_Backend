<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Order\OrdersSales;
use App\Services\PreordenDelChat;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Lo que espera algo de alguien, para enseñarlo en el inicio hasta que se resuelva.
class PreordenesPendientesController extends Controller
{
    private const TOPE = 20;

    public function delComprador(Request $request)
    {
        $yo = (int) $request->user()->user_id;

        $chats = DB::table('chat_participants as p')
            ->join('chats as c', 'c.chat_id', '=', 'p.chat_id')
            ->where('p.user_id', $yo)
            ->whereNotNull('c.busines_id')
            ->pluck('c.busines_id', 'c.chat_id');

        $tarjetas = $this->tarjetasDe($chats->keys());
        $nombres = DB::table('business')->whereIn('busines_id', $chats->values())->pluck('name', 'busines_id');

        $lista = $tarjetas->map(function ($fila) use ($yo, $chats, $nombres) {
            $t = $fila->tarjeta;
            $estado = $t['status'] ?? PreordenDelChat::PENDIENTE;
            $suya = (int) $fila->user_id === $yo;

            $que = match (true) {
                $estado === PreordenDelChat::CONFIRMADA => 'por_pagar',
                $estado === PreordenDelChat::PAGADA && $this->esperandoPago($t) => 'por_pagar',
                $estado === PreordenDelChat::PENDIENTE && ! $suya => 'por_confirmar',
                $estado === PreordenDelChat::PENDIENTE && $suya => 'esperando_al_negocio',
                default => null,
            };
            if ($que === null) {
                return null;
            }

            return $this->forma($fila, $que, [
                'business_id' => (int) $chats[$fila->chat_id],
                'nombre' => $nombres[$chats[$fila->chat_id]] ?? 'El negocio',
            ]);
        })->filter()->take(self::TOPE)->values();

        return response()->json(['data' => $lista]);
    }

    public function delNegocio(Request $request)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $chats = DB::table('chats')->where('busines_id', $negocioId)->pluck('chat_id');
        $tarjetas = $this->tarjetasDe($chats);
        $clientes = DB::table('user')->whereIn('user_id', $tarjetas->pluck('user_id'))->pluck('name', 'user_id');

        $lista = $tarjetas
            ->filter(fn ($f) => PreordenDelChat::deQuien($f->tarjeta) === 'cliente'
                && ($f->tarjeta['status'] ?? PreordenDelChat::PENDIENTE) === PreordenDelChat::PENDIENTE)
            ->map(fn ($f) => $this->forma($f, 'por_confirmar', [
                'user_id' => (int) $f->user_id,
                'nombre' => $clientes[$f->user_id] ?? 'Un cliente',
            ]))
            ->take(self::TOPE)
            ->values();

        return response()->json(['data' => $lista]);
    }

    /** @param  Collection<int, mixed>  $chatIds */
    private function tarjetasDe(Collection $chatIds): Collection
    {
        if ($chatIds->isEmpty()) {
            return collect();
        }

        return DB::table('messages')
            ->whereIn('chat_id', $chatIds)
            ->where('content', 'like', '%"type":"preorder"%')
            ->orderByDesc('message_id')
            ->limit(200)
            ->get(['message_id', 'chat_id', 'user_id', 'content', 'created_at'])
            ->map(function ($f) {
                $f->tarjeta = PreordenDelChat::tarjeta((string) $f->content);

                return $f;
            })
            ->filter(fn ($f) => $f->tarjeta !== null);
    }

    /** @param  array<string, mixed>  $t */
    private function esperandoPago(array $t): bool
    {
        return isset($t['order_id']) && (bool) OrdersSales::find((int) $t['order_id'])?->esperandoPago();
    }

    /** @param  array<string, mixed>  $con */
    private function forma(object $fila, string $que, array $con): array
    {
        $t = $fila->tarjeta;

        return [
            'chat_id' => (int) $fila->chat_id,
            'message_id' => (int) $fila->message_id,
            'que' => $que,
            'con' => $con,
            'productos' => count($t['items'] ?? []),
            'total' => (float) ($t['total'] ?? PreordenDelChat::total($t['items'] ?? [])),
            'tarjeta' => $t,
            'created_at' => $fila->created_at,
        ];
    }
}
