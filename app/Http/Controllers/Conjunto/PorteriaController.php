<?php

namespace App\Http\Controllers\Conjunto;

use App\Http\Controllers\Controller;
use App\Models\Domiciliary;
use App\Services\AccesoAlConjunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La portería del conjunto.
 *
 * El celador escanea el código de la app del domiciliario —o escribe su
 * cédula— y esto responde una sola pregunta: ¿tiene pedidos para ESTE
 * conjunto?
 *
 * El conjunto NO llega por parámetro. Lo pone `EnsureComplexStaff` desde la
 * sesión. Si viniera en la petición, un celador podría consultar la portería
 * de otro edificio cambiando un número, y de paso vería a qué apartamento va
 * cada pedido de un conjunto que no es el suyo.
 */
class PorteriaController extends Controller
{
    public function __construct(private AccesoAlConjunto $acceso)
    {
    }

    public function verificar(Request $request)
    {
        $datos = $request->validate([
            'code'     => 'required_without:document|nullable|string|max:20',
            'document' => 'required_without:code|nullable|string|max:50',
        ]);

        $complexId = (int) $request->attributes->get('complex_id');

        $porCodigo    = !empty($datos['code']);
        $domiciliario = $porCodigo
            ? $this->acceso->resolverCodigo($datos['code'])
            : $this->acceso->resolverCedula($datos['document']);

        if (!$domiciliario) {
            return response()->json([
                'message' => $porCodigo
                    // Se distingue a propósito: un código caducado es lo más
                    // frecuente, y decir "no existe" haría que el celador
                    // buscara el problema donde no está.
                    ? 'El código no es válido o ya venció. Pídele que genere uno nuevo.'
                    : 'No encontramos un domiciliario con esa cédula.',
                'allowed' => false,
            ], 404);
        }

        $pedidos = $this->acceso->pedidosEnElConjunto($domiciliario, $complexId);

        if ($pedidos->isEmpty()) {
            return response()->json([
                'message' => 'Este domiciliario no tiene pedidos en el conjunto',
                'allowed' => false,
                'domiciliary' => $this->ficha($domiciliario),
            ]);
        }

        // Solo se registra la entrada cuando procede: una entrada anotada de
        // alguien a quien no se dejó pasar ensuciaría el historial.
        $entrada = DB::table('complex_entries')->insertGetId([
            'complex_id'     => $complexId,
            'domiciliary_id' => $domiciliario->domiciliary_id,
            'method'         => $porCodigo ? 'codigo' : 'cedula',
            'orders_count'   => $pedidos->count(),
            'orders'         => $pedidos->toJson(),
            'registered_by'  => $request->user()->user_id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json([
            'message'     => 'Puede entrar.',
            'allowed'     => true,
            'entry_id'    => $entrada,
            'domiciliary' => $this->ficha($domiciliario),
            'orders'      => $pedidos,
        ]);
    }

    /** Las entradas registradas en este conjunto. */
    public function entradas(Request $request)
    {
        $complexId = (int) $request->attributes->get('complex_id');

        $filas = DB::table('complex_entries as e')
            ->where('e.complex_id', $complexId)
            ->leftJoin('domiciliary as d', 'd.domiciliary_id', '=', 'e.domiciliary_id')
            ->leftJoin('user as u', 'u.user_id', '=', 'd.user_id')
            ->orderByDesc('e.created_at')
            ->limit(200)
            ->get([
                'e.id', 'e.method', 'e.orders_count', 'e.created_at',
                'u.name as domiciliary_name',
            ]);

        return response()->json(['data' => $filas]);
    }

    private function ficha(Domiciliary $d): array
    {
        return [
            'domiciliary_id' => $d->domiciliary_id,
            'name'           => $d->user->name ?? null,
            'document'       => $d->document,
            'phone'          => $d->user->phone ?? null,
        ];
    }
}
