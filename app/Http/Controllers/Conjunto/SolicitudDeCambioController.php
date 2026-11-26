<?php

declare(strict_types=1);

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conjunto\SolicitarCambioDeFormaRequest;
use App\Models\Operacion\Pqrs;
use App\Services\RadicacionDePqrs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «SOLICITAR CAMBIO DE TORRES O APARTAMENTOS».
 *
 * El administrador no puede tocar `towers_count` ni `apartments_per_tower`
 * (ver `MiConjuntoController::actualizar`): de ahí sale el denominador con
 * que la plataforma mide el edificio. Lo que sí puede es PEDIRLO, y antes eso
 * era un botón a WhatsApp: sin radicado, sin plazo y sin que su pantalla
 * supiera que ya lo había pedido.
 *
 * Ahora se radica un PQRS de tipo petición —el mismo camino de la ayuda—
 * con lo que hay y lo que se pide escrito en orden, y queda la solicitud en
 * `complex_change_requests`. Mientras el caso siga abierto o en gestión, la
 * solicitud está «en revisión» y no se puede mandar otra encima.
 */
class SolicitudDeCambioController extends Controller
{
    /** La última solicitud del conjunto, con su estado (o null). */
    public function ultima(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        return response()->json(['data' => $this->forma($this->ultimaDe($complexId))]);
    }

    public function solicitar(SolicitarCambioDeFormaRequest $request, RadicacionDePqrs $radicacion)
    {
        $complexId = (int) $request->attributes->get('complex_id');
        $datos = $request->validated();
        $usuario = $request->user();

        $previa = $this->forma($this->ultimaDe($complexId));
        if ($previa && $previa['estado'] === 'en_revision') {
            return response()->json([
                'message' => 'Ya tienes una solicitud en revisión (radicado '.$previa['code'].').',
                'data' => $previa,
            ], 409);
        }

        $conjunto = DB::table('residential_complexes')->where('complex_id', $complexId)->first();

        if ((int) $conjunto->towers_count === (int) $datos['towers_count']
            && (int) $conjunto->apartments_per_tower === (int) $datos['apartments_per_tower']) {
            return response()->json([
                'message' => 'Es igual a lo que ya tiene el conjunto.',
                'errors' => ['towers_count' => ['Cambia las torres o los apartamentos por torre.']],
            ], 422);
        }

        $id = DB::transaction(function () use ($conjunto, $datos, $usuario, $radicacion, $complexId) {
            $pqrs = $radicacion->radicar($usuario, [
                'type' => 'peticion',
                'subject' => 'Cambio de torres o apartamentos · '.$conjunto->name,
                'description' => $this->descripcion($conjunto, $datos, $usuario),
            ]);

            return DB::table('complex_change_requests')->insertGetId([
                'complex_id' => $complexId,
                'requested_by' => $usuario->user_id,
                'towers_before' => $conjunto->towers_count,
                'apartments_before' => $conjunto->apartments_per_tower,
                'towers_count' => $datos['towers_count'],
                'apartments_per_tower' => $datos['apartments_per_tower'],
                'note' => $datos['note'] ?? null,
                'pqrs_id' => $pqrs->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $solicitud = $this->forma($this->ultimaDe($complexId));

        return response()->json([
            'message' => 'Solicitud enviada. Te avisamos cuando el equipo la revise.',
            'data' => $solicitud,
        ], 201);
    }

    /**
     * El caso escrito para quien lo atiende: qué conjunto, lo que hay, lo que
     * se pide, la nota y quién lo pidió. Sin tener que abrir otra pantalla.
     */
    private function descripcion(object $conjunto, array $datos, $usuario): string
    {
        $unidades = fn ($t, $a) => (int) $t * (int) $a;

        return implode("\n", array_filter([
            "Solicitud de cambio de torres o apartamentos del conjunto «{$conjunto->name}» (id {$conjunto->complex_id}).",
            '',
            "Hoy: {$conjunto->towers_count} torres × {$conjunto->apartments_per_tower} apartamentos por torre ("
                .$unidades($conjunto->towers_count, $conjunto->apartments_per_tower).' unidades).',
            "Pide: {$datos['towers_count']} torres × {$datos['apartments_per_tower']} apartamentos por torre ("
                .$unidades($datos['towers_count'], $datos['apartments_per_tower']).' unidades).',
            '',
            ! empty($datos['note']) ? 'Nota del administrador: '.$datos['note'] : null,
            "Lo pidió: {$usuario->name} ({$usuario->email}), desde el panel de aliados.",
        ], fn ($l) => $l !== null));
    }

    private function ultimaDe(int $complexId): ?object
    {
        return DB::table('complex_change_requests as r')
            ->leftJoin('pqrs as p', 'p.id', '=', 'r.pqrs_id')
            ->where('r.complex_id', $complexId)
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->first([
                'r.id', 'r.towers_before', 'r.apartments_before', 'r.towers_count',
                'r.apartments_per_tower', 'r.note', 'r.created_at',
                'p.code', 'p.state as pqrs_state', 'p.resolution',
            ]);
    }

    /** `en_revision` mientras el caso esté abierto o en gestión; si no, cómo terminó. */
    private function forma(?object $r): ?array
    {
        if (! $r) {
            return null;
        }

        $estado = match ((int) ($r->pqrs_state ?? Pqrs::ABIERTO)) {
            Pqrs::RESUELTO => 'resuelta',
            Pqrs::CERRADO => 'cerrada',
            default => 'en_revision',
        };

        return [
            'id' => (int) $r->id,
            'towers_before' => $r->towers_before === null ? null : (int) $r->towers_before,
            'apartments_before' => $r->apartments_before === null ? null : (int) $r->apartments_before,
            'towers_count' => (int) $r->towers_count,
            'apartments_per_tower' => (int) $r->apartments_per_tower,
            'note' => $r->note,
            'created_at' => (string) $r->created_at,
            'code' => $r->code,
            'estado' => $estado,
            'resolution' => $estado === 'en_revision' ? null : $r->resolution,
        ];
    }
}
