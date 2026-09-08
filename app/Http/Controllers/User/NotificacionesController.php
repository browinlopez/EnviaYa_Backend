<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Models\Notification;
use App\Services\MunicipioPorNombre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class NotificacionesController extends Controller
{
    use ComprobarPertenencia;

    /*
     * LA CAMPANA
     *
     * Estos tres métodos estaban comentados enteros y no había ruta que llevara
     * a ellos, así que la tabla `notifications` llevaba desde el principio sin
     * usar y la pantalla de avisos de la app tenía la lista escrita a mano en
     * blanco.
     *
     * Se piden por el usuario en sesión y no por un `user_id` recibido: tal
     * como estaba escrito, bastaba mandar el número de otra persona para leer
     * sus avisos.
     */
    public function getNotifications(Request $request)
    {
        $avisos = Notification::where('user_id', $request->user()->user_id)
            ->where('state', true)
            ->orderByDesc('date')
            ->limit(50)
            ->get();

        return response()->json([
            'notifications' => $avisos,
            // El número que va en la burbuja de la campana.
            'unread' => $avisos->where('read', false)->count(),
        ]);
    }

    public function markNotificationAsRead(Request $request)
    {
        $datos = $request->validate([
            'notification_id' => 'required|integer',
        ]);

        $aviso = Notification::where('notification_id', $datos['notification_id'])
            ->where('user_id', $request->user()->user_id)
            ->first();

        // Un aviso ajeno se responde igual que uno inexistente: no hay por qué
        // confirmarle a nadie que el número acertó.
        if (!$aviso) {
            return response()->json(['message' => 'Aviso no encontrado'], 404);
        }

        $aviso->read = true;
        $aviso->save();

        return response()->json(['message' => 'Aviso marcado como leído']);
    }

    public function markAllNotificationsAsRead(Request $request)
    {
        $cuantos = Notification::where('user_id', $request->user()->user_id)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['message' => 'Avisos marcados', 'count' => $cuantos]);
    }
}
