<?php

namespace App\Http\Controllers\Negocio;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Models\Operacion\DomiciliaryDocument;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * LA TIENDA SUBE LOS PAPELES DE SU DOMICILIARIO.
 *
 * El domiciliario de barrio suele ser el sobrino o el vecino, y quien tiene la
 * foto del SOAT en el celular es el tendero. Esperar a que la persona lo suba
 * desde su app dejaba la ficha en «Falta» días enteros mientras la moto ya
 * estaba repartiendo.
 *
 * SUBIR Y VER, SÓLO SI ES SUYO. La tienda sube y consulta los papeles de los
 * domiciliarios VINCULADOS a ella: los necesita para decidir a quién le
 * entrega pedidos. El vínculo ya no se consigue con el id a secas —hay que
 * encontrarlo con un dato exacto suyo (ver AltaDeDomiciliarioController)—, y
 * `ver` lo exige. El archivo se entrega a través del backend, nunca con una
 * URL pública. Va a la carpeta de la PERSONA (`usuarios/<id>`), igual que lo
 * que sube el equipo.
 *
 * REEMPLAZAR NO BORRA. Si ya había uno del mismo tipo se archiva (`state = 0`)
 * y el nuevo pasa a ser el vigente: el historial de papeles de una persona es
 * lo que se revisa cuando algo sale mal en la calle.
 *
 * El acuerdo de vinculación NO entra acá: lo firma el domiciliario desde su
 * propia sesión, y por eso `acuerdo` no es un tipo admitido.
 */
class DocumentosDelDomiciliarioController extends Controller
{
    /** Los que se le pueden subir desde la tienda. */
    public const TIPOS = ['cedula', 'licencia', 'soat', 'tecnomecanica', 'arl', 'eps'];

    /** Los que tienen fecha de vencimiento y sin ella no sirven para avisar. */
    private const CON_VENCIMIENTO = ['licencia', 'soat', 'tecnomecanica'];

    public function subir(Request $request, MediaService $medios, int $id)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        /* El vínculo con ESTA tienda es la autorización, como en la ficha: sin
           él, cualquier tendero podría escribir en los papeles de alguien que
           no reparte para él. */
        $d = Domiciliary::with('user')
            ->whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->findOrFail($id);

        $datos = $request->validate([
            'type'       => 'required|in:' . implode(',', self::TIPOS),
            // Foto o PDF: es lo que sale de un celular o de un escáner.
            'file'       => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:8192',
            'number'     => 'nullable|string|max:60',
            /* Un SOAT sin fecha no puede avisar cuando se vence, que es para
               lo que se pide. `required_if` y no una regla a mano: Laravel no
               corre las reglas propias sobre un campo que no llegó, y así un
               SOAT sin fecha entraba igual. */
            'expires_at' => 'nullable|required_if:type,' . implode(',', self::CON_VENCIMIENTO) . '|date|after:today',
        ], [
            'expires_at.required_if' => 'Ponle la fecha en que se vence.',
            'file.mimes'       => 'Sube una foto (JPG, PNG o WEBP) o un PDF.',
            'file.max'         => 'El archivo pesa más de 8 MB.',
            'expires_at.after' => 'Esa fecha ya pasó: así el documento ya está vencido.',
        ]);

        if (!$medios->configurado()) {
            return response()->json([
                'message' => 'El almacenamiento de archivos no está configurado en el servidor.',
            ], 503);
        }

        try {
            $archivo = $medios->subir(
                'usuarios',
                $d->user_id,
                $d->user?->name,
                $request->file('file'),
                'documentos',
                $request->user()->user_id,
            );
        } catch (\RuntimeException $e) {
            // Sólo los fallos que MediaService explica en español; el resto
            // lo atiende el manejador general sin detalles internos.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tienda = DB::table('business')->where('busines_id', $negocioId)->value('name');

        DB::transaction(function () use ($d, $datos, $archivo, $tienda) {
            DomiciliaryDocument::where('domiciliary_id', $d->domiciliary_id)
                ->where('type', $datos['type'])
                ->where('state', 1)
                ->update(['state' => 0]);

            DomiciliaryDocument::create([
                'domiciliary_id' => $d->domiciliary_id,
                'type'           => $datos['type'],
                'number'         => $datos['number'] ?? null,
                'expires_at'     => $datos['expires_at'] ?? null,
                'media_id'       => (int) $archivo['id'],
                'notes'          => mb_substr('Lo subió la tienda ' . ($tienda ?? ''), 0, 255),
                'state'          => 1,
            ]);
        });

        return response()->json(['message' => 'Documento guardado.'], 201);
    }

    /**
     * El archivo vigente de uno de sus papeles, para verlo en el panel.
     *
     * Antes la tienda subía pero no veía. Ver lo que ella misma subió —o lo
     * que tiene su domiciliario— es lo que le deja comprobar que la foto
     * quedó legible antes de mandarlo a la calle. Solo de los domiciliarios
     * vinculados a esta tienda, igual que al subir.
     */
    public function ver(Request $request, MediaService $medios, int $id, string $tipo)
    {
        $negocioId = (int) $request->attributes->get('busines_id');

        $d = Domiciliary::whereHas('businesses', fn ($q) => $q->where('business.busines_id', $negocioId)->where('business_domiciliary.state', 1))
            ->findOrFail($id);

        $mediaId = DomiciliaryDocument::where('domiciliary_id', $d->domiciliary_id)
            ->where('type', $tipo)
            ->where('state', 1)
            ->value('media_id');

        abort_unless($mediaId, 404, 'Ese documento no tiene archivo.');

        return $medios->entregar((int) $mediaId);
    }
}
