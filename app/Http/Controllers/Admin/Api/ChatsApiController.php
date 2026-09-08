<?php

namespace App\Http\Controllers\Admin\Api;

use App\Services\Ajustes;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Conjunto\ComplexStaff;
use App\Models\Operacion\DomiciliaryDocument;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use App\Models\User;
use App\Services\BusinessMediaService;
use App\Services\ContratoService;
use App\Services\MediaService;
use App\Services\VinculosDelRol;
use App\Support\ListadoPaginado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChatsApiController extends Controller
{

    public function chats(Request $request)
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

        return response()->json(
            $chats->map(function ($c) use ($participantes, $ultimos) {
                $c->participants = ($participantes[$c->chat_id] ?? collect())->values();
                $contenido = $ultimos[$c->chat_id]->content ?? null;
                // Una pre-orden es JSON: mostrarlo crudo en la lista no dice
                // nada, así que se resume.
                $c->last_message = $contenido && str_starts_with(trim($contenido), '{')
                    ? '[Pre-orden]'
                    : $contenido;
                return $c;
            })
        );
    }

    public function chatMessages($chatId)
    {
        abort_if(!DB::table('chats')->where('chat_id', $chatId)->exists(), 404, 'La conversación no existe.');

        return response()->json(
            DB::table('messages as m')
                ->leftJoin('user as u', 'u.user_id', '=', 'm.user_id')
                ->where('m.chat_id', $chatId)
                ->orderBy('m.message_id')
                ->get(['m.message_id', 'm.chat_id', 'm.user_id', 'm.role_id', 'm.content', 'm.created_at', 'u.name as user_name'])
        );
    }

    /* ==================================================================
       AUDITORÍA
       ================================================================== */
}
