<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Queries\ChatsDelPanel;
use Illuminate\Http\Request;

class ChatsApiController extends Controller
{
    /**
     * El listado de conversaciones.
     *
     * LAS CONSULTAS VIVEN EN `App\Queries\ChatsDelPanel`, que es lo que §12
     * pide. Eran cuatro consultas cruzadas en memoria con tres agregados
     * dentro de este método; aquí queda lo que sí es del controlador:
     * responder.
     */
    public function chats(Request $request)
    {
        return response()->json((new ChatsDelPanel)->listado());
    }

    public function chatMessages($chatId)
    {
        $chats = new ChatsDelPanel;

        abort_if(! $chats->existe($chatId), 404, 'La conversación no existe.');

        return response()->json($chats->mensajes($chatId));
    }

    /* ==================================================================
       AUDITORÍA
       ================================================================== */
}
