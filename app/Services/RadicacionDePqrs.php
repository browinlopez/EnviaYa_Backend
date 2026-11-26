<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Operacion\Pqrs;
use App\Models\User;

/**
 * RADICAR UN CASO EN LA BANDEJA DE PQRS, desde fuera del panel interno.
 *
 * Un solo camino para la ayuda de la app (y del panel de aliados) y para las
 * solicitudes que nacen de una pantalla —como el cambio de torres de un
 * conjunto—: el radicado, la prioridad por tipo, el plazo y los datos de
 * contacto salen siempre de la misma regla. Los datos de contacto son los de
 * la CUENTA de quien radica, nunca lo que teclee.
 */
class RadicacionDePqrs
{
    /**
     * @param  array{type: string, subject: string, description: string, order_id?: ?int, business_id?: ?int, domiciliary_id?: ?int}  $datos
     */
    public function radicar(User $usuario, array $datos): Pqrs
    {
        $prioridad = $datos['type'] === 'reclamo' ? 'alta' : 'media';

        return Pqrs::create([
            'code' => Pqrs::siguienteRadicado(),
            'type' => $datos['type'],
            'channel' => 'app',
            'priority' => $prioridad,
            'user_id' => $usuario->user_id,
            'order_id' => $datos['order_id'] ?? null,
            'business_id' => $datos['business_id'] ?? null,
            'domiciliary_id' => $datos['domiciliary_id'] ?? null,
            'contact_name' => $usuario->name,
            'contact_email' => $usuario->email,
            'contact_phone' => $usuario->phone,
            'subject' => $datos['subject'],
            'description' => $datos['description'],
            'state' => Pqrs::ABIERTO,
            'due_at' => now()->addDays(Pqrs::PLAZOS[$prioridad]),
        ]);
    }
}
