<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seguridad\ReportarChatRequest;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatParticipant;
use App\Models\Chat\Message;
use App\Models\Seguridad\ChatReport;
use App\Support\ResumenDeMensaje;

/**
 * ────────────────────────────────────────────────────────────────────────
 * DENUNCIAR A UN COMPRADOR DESDE EL CHAT.
 *
 * El tendero habla con sus clientes por la app y hasta ahora no tenía dónde
 * contar que uno lo está extorsionando. Esto no es el botón de SOS: el SOS es
 * «me está pasando algo AHORA mismo»; esto es «guarda esto para que lo
 * revisen», y por eso no avisa a nadie en caliente ni corta la conversación.
 *
 * TRES COSAS NO LLEGAN DEL TELÉFONO, y no por desconfiar del teléfono en
 * abstracto, sino porque un reporte que el cliente pueda dirigir contra quien
 * quiera no sirve de prueba de nada:
 *
 *  · A QUIÉN SE DENUNCIA. Es el otro participante de la conversación.
 *  · DE QUÉ TIENDA. Sale del middleware `negocio`, como el resto del grupo.
 *  · QUÉ SE DIJO. El servidor lee él mismo los últimos mensajes de ESE chat.
 * ────────────────────────────────────────────────────────────────────────
 */
class ReporteDeChatController extends Controller
{
    /** El comprador. Es a quien se puede denunciar desde aquí. */
    private const COMPRADOR = 1;

    /**
     * Dos denuncias del mismo tendero sobre el mismo chat dentro de esta
     * ventana son la misma: el dedo se va, o se manda y se vuelve a entrar
     * porque no quedó claro que ya estaba. Igual que en `SosController`.
     */
    private const VENTANA_REPETIDO = 10;

    public function reportar(ReportarChatRequest $request, int $chatId)
    {
        $usuario = $request->user();
        $negocioId = (int) $request->attributes->get('busines_id');

        $chat = Chat::find($chatId);

        if (! $chat) {
            return response()->json(['message' => 'Esa conversación no existe.'], 404);
        }

        /*
         * SIN ESTO, CUALQUIERA DENUNCIA CONVERSACIONES AJENAS pasando un
         * `chat_id` a mano, y de paso se lleva los mensajes de otros como
         * «prueba». La puerta es la misma que usa el chat para dejar leer.
         */
        $participantes = ChatParticipant::where('chat_id', $chatId)->get();

        $participo = $participantes->contains(
            fn ($p) => (int) $p->user_id === (int) $usuario->user_id,
        );

        if (! $participo) {
            return response()->json(['message' => 'Esa conversación no es tuya.'], 403);
        }

        $reportado = $participantes->first(
            fn ($p) => (int) $p->user_id !== (int) $usuario->user_id
                && (int) $p->role_id === self::COMPRADOR,
        );

        if (! $reportado) {
            return response()->json([
                'message' => 'Desde aquí sólo se puede reportar a un comprador.',
            ], 422);
        }

        $datos = $request->validated();

        /*
         * El reporte abierto de hace un momento se completa en vez de
         * duplicarse. No se le toca la prueba: la de la primera vez es la
         * buena, porque es la de cuando pasó.
         */
        $abierto = ChatReport::where('chat_id', $chatId)
            ->where('reported_by', $usuario->user_id)
            ->where('state', ChatReport::ABIERTO)
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_REPETIDO))
            ->latest('id')
            ->first();

        if ($abierto) {
            $abierto->update([
                'reason' => $datos['reason'],
                'description' => $datos['description'] ?? $abierto->description,
            ]);

            return response()->json([
                'message' => 'Lo tenemos. El equipo lo revisa.',
                'id' => $abierto->id,
            ]);
        }

        $reporte = ChatReport::create([
            'chat_id' => $chatId,
            'reported_by' => $usuario->user_id,
            'reported_user_id' => $reportado->user_id,
            'business_id' => $negocioId ?: null,
            'reason' => $datos['reason'],
            'description' => $datos['description'] ?? null,
            'evidence' => $this->loQueSeDijo($chatId),
            'state' => ChatReport::ABIERTO,
        ]);

        return response()->json([
            'message' => 'Lo recibimos. El equipo lo revisa.',
            'id' => $reporte->id,
        ], 201);
    }

    /**
     * Los últimos mensajes de la conversación, en orden.
     *
     * SE GUARDAN LEGIBLES. `content` también lleva tarjetas en JSON —las
     * pre-órdenes—, y `ResumenDeMensaje` ya sabe convertirlas en prosa. Sin
     * eso, quien abra el reporte vería `{"type":"preorder",…}` donde debería
     * leer qué se dijo.
     */
    private function loQueSeDijo(int $chatId): array
    {
        return Message::where('chat_id', $chatId)
            ->orderByDesc('message_id')
            ->limit(ChatReport::MENSAJES_DE_PRUEBA)
            ->get(['message_id', 'user_id', 'content', 'created_at'])
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'message_id' => (int) $m->message_id,
                'user_id' => (int) $m->user_id,
                'texto' => ResumenDeMensaje::para($m->content),
                'cuando' => optional($m->created_at)->toIso8601String(),
            ])
            ->all();
    }
}
