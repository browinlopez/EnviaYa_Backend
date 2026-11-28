<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Preorden;
use App\Models\Order\OrdersSales;
use App\Services\PreordenDelChat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Lo que espera algo de alguien, para enseñarlo en el inicio hasta que se resuelva.
class PreordenesPendientesController extends Controller
{
    private const TOPE = 20;

    private const DIAS = 14;

    public function delComprador(Request $request)
    {
        $yo = (int) $request->user()->user_id;

        $filas = Preorden::where('buyer_user_id', $yo)
            ->whereIn('status', [PreordenDelChat::PENDIENTE, PreordenDelChat::CONFIRMADA, PreordenDelChat::PAGADA])
            ->where('updated_at', '>', now()->subDays(self::DIAS))
            ->orderByDesc('id')
            ->limit(self::TOPE * 3)
            ->get();

        $nombres = DB::table('business')->whereIn('busines_id', $filas->pluck('busines_id')->filter())->pluck('name', 'busines_id');

        $lista = $filas->map(function (Preorden $f) use ($yo, $nombres) {
            $que = match (true) {
                $f->status === PreordenDelChat::CONFIRMADA => 'por_pagar',
                $f->status === PreordenDelChat::PAGADA && $this->esperandoPago($f) => 'por_pagar',
                $f->status === PreordenDelChat::PENDIENTE && (int) $f->created_by !== $yo => 'por_confirmar',
                $f->status === PreordenDelChat::PENDIENTE => 'esperando_al_negocio',
                default => null,
            };

            return $que === null ? null : $this->forma($f, $que, [
                'business_id' => (int) $f->busines_id,
                'nombre' => $nombres[$f->busines_id] ?? 'El negocio',
            ]);
        })->filter()->take(self::TOPE)->values();

        return response()->json(['data' => $lista]);
    }

    public function delNegocio(Request $request)
    {
        $filas = Preorden::where('busines_id', (int) $request->attributes->get('busines_id'))
            ->where('status', PreordenDelChat::PENDIENTE)
            ->where('de', 'cliente')
            ->where('updated_at', '>', now()->subDays(self::DIAS))
            ->orderBy('id')
            ->limit(self::TOPE)
            ->get();

        $clientes = DB::table('user')->whereIn('user_id', $filas->pluck('buyer_user_id')->filter())->pluck('name', 'user_id');

        $lista = $filas->map(fn (Preorden $f) => $this->forma($f, 'por_confirmar', [
            'user_id' => (int) $f->buyer_user_id,
            'nombre' => $clientes[$f->buyer_user_id] ?? 'Un cliente',
        ]))->values();

        return response()->json(['data' => $lista]);
    }

    private function esperandoPago(Preorden $f): bool
    {
        return $f->order_id !== null && (bool) OrdersSales::find($f->order_id)?->esperandoPago();
    }

    /** @param  array<string, mixed>  $con */
    private function forma(Preorden $f, string $que, array $con): array
    {
        $tarjeta = PreordenDelChat::tarjeta((string) DB::table('messages')->where('message_id', $f->message_id)->value('content'));

        return [
            'chat_id' => (int) $f->chat_id,
            'message_id' => (int) $f->message_id,
            'preorden_id' => $f->id,
            'que' => $que,
            'con' => $con,
            'productos' => count($f->items),
            'total' => (float) $f->total,
            'tarjeta' => $tarjeta ?? ['type' => 'preorder', 'items' => $f->items, 'total' => (float) $f->total],
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
