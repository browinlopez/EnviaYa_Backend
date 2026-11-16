<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LAS LECTURAS DE CONVERSACIONES DEL PANEL INTERNO.
 *
 * §12 pide las consultas complejas en `Queries/`. Esto eran CUATRO consultas
 * que se cruzan en memoria, con tres agregados y un `GROUP BY`, escritas
 * dentro de `ChatsApiController`.
 *
 * NO CAMBIA NI UNA COMA. Se mueven con sus dos comentarios, y los dos cuentan
 * una decisión que no es obvia: por qué el último mensaje se trae en dos
 * consultas en vez de una por chat, y por qué una pre-orden se resume en vez
 * de enseñarse cruda.
 */
class ChatsDelPanel
{
    /**
     * Cada conversación con sus participantes, sus cifras y su último mensaje.
     *
     * `stdClass` y no `object`: los genéricos de `Collection` son INVARIANTES,
     * así que `Collection<int, object>` NO acepta un
     * `Collection<int, stdClass>`.
     *
     * @return Collection<int, stdClass>
     */
    public function listado(): Collection
    {
        $chats = DB::table('chats as c')
            ->leftJoin('messages as m', 'm.chat_id', '=', 'c.chat_id')
            ->groupBy('c.chat_id', 'c.type', 'c.created_at')
            ->orderByDesc(DB::raw('MAX(m.created_at)'))
            ->get([
                'c.chat_id', 'c.type', 'c.created_at',
                DB::raw('COUNT(m.message_id) as messages_count'),
                DB::raw('MAX(m.created_at) as last_at'),
                DB::raw("SUM(CASE WHEN m.content LIKE '%\"type\":\"preorder\"%' THEN 1 ELSE 0 END) as preorders_count"),
            ]);

        $participantes = DB::table('chat_participants as cp')
            ->leftJoin('user as u', 'u.user_id', '=', 'cp.user_id')
            ->get(['cp.chat_id', 'u.user_id', 'u.name', 'u.rol'])
            ->groupBy('chat_id');

        // El último mensaje de cada chat, sin N+1: se trae el máximo id por
        // chat y luego esos mensajes en una sola consulta.
        $ultimosIds = DB::table('messages')
            ->selectRaw('MAX(message_id) as id')
            ->groupBy('chat_id')
            ->pluck('id');

        $ultimos = DB::table('messages')
            ->whereIn('message_id', $ultimosIds)
            ->get(['chat_id', 'content'])
            ->keyBy('chat_id');

        return $chats->map(function ($c) use ($participantes, $ultimos) {
            $c->participants = ($participantes[$c->chat_id] ?? collect())->values();
            $contenido = $ultimos[$c->chat_id]->content ?? null;
            // Una pre-orden es JSON: mostrarlo crudo en la lista no dice
            // nada, así que se resume.
            $c->last_message = $contenido && str_starts_with(trim($contenido), '{')
                ? '[Pre-orden]'
                : $contenido;

            return $c;
        });
    }

    /** Si esa conversación existe. El 404 lo decide el controlador. */
    public function existe(int|string $chatId): bool
    {
        return DB::table('chats')->where('chat_id', $chatId)->exists();
    }

    /**
     * Los mensajes de una conversación, con el nombre de quien los escribió.
     *
     * @return Collection<int, stdClass>
     */
    public function mensajes(int|string $chatId): Collection
    {
        return DB::table('messages as m')
            ->leftJoin('user as u', 'u.user_id', '=', 'm.user_id')
            ->where('m.chat_id', $chatId)
            ->orderBy('m.message_id')
            ->get(['m.message_id', 'm.chat_id', 'm.user_id', 'm.role_id', 'm.content', 'm.created_at', 'u.name as user_name']);
    }
}
