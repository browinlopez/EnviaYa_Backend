<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CrearPropietarioRequest;
use App\Models\Order\OrdersSales;
use App\Queries\PropietariosDelPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class PropietariosApiController extends Controller
{
    /** El listado del panel. La consulta vive en `Queries/PropietariosDelPanel`. */
    public function owners()
    {
        return response()->json(
            (new PropietariosDelPanel(OrdersSales::ENTREGADO))->listado()
        );
    }

    /** Tipos de documento y negocios libres, para los selectores de la ficha. */
    public function ownerOptions()
    {
        return response()->json(
            (new PropietariosDelPanel(OrdersSales::ENTREGADO))->opcionesDeLaFicha()
        );
    }

    /**
     * Crea un propietario: la cuenta de usuario (rol 2) y su ficha `owner`.
     *
     * Se hace en una transacción porque un usuario sin fila en `owner` puede
     * entrar a la app pero no administrar nada, y eso es peor que no existir.
     */
    public function storeOwner(CrearPropietarioRequest $request)
    {
        $datos = $request->validated();

        $ownerId = DB::transaction(function () use ($datos) {
            $userId = DB::table('user')->insertGetId([
                'name' => $datos['name'],
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
                'phone' => $datos['phone'] ?? null,
                'address' => $datos['address'] ?? null,
                'rol' => 2,
                'state' => (int) ($datos['state'] ?? 1),
                'qualification' => 0,
                // Lo da de alta un administrador: sin verificar, el login lo
                // rechazaría y la cuenta nacería inservible.
                'email_verified_at' => now(),
                // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                // es el reloj del servidor, que en el VPS no es el de Bogotá.
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ownerId = DB::table('owner')->insertGetId([
                'user_id' => $userId,
                'document_type_id' => $datos['document_type_id'] ?? null,
                'document_number' => $datos['document_number'] ?? null,
                'birthdate' => $datos['birthdate'] ?? null,
                'contact_secondary' => $datos['contact_secondary'] ?? null,
                'notes' => $datos['notes'] ?? null,
                'state' => (int) ($datos['state'] ?? 1),
            ]);

            $this->asignarNegocios($ownerId, $datos['business_ids'] ?? []);

            return $ownerId;
        });

        return response()->json(['message' => 'Propietario creado.', 'owner_id' => $ownerId], 201);
    }

    public function updateOwner(Request $request, $id)
    {
        $owner = DB::table('owner')->where('owner_id', $id)->first();
        abort_if(! $owner, 404, 'El propietario no existe.');

        $datos = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('user', 'email')->ignore($owner->user_id, 'user_id')],
            'password' => 'sometimes|nullable|string|min:6',
            'phone' => 'sometimes|nullable|string|max:20',
            'address' => 'sometimes|nullable|string|max:255',
            'document_type_id' => 'sometimes|nullable|integer|exists:document_types,id',
            'document_number' => 'sometimes|nullable|string|max:50',
            'birthdate' => 'sometimes|nullable|date',
            'contact_secondary' => 'sometimes|nullable|string|max:45',
            'notes' => 'sometimes|nullable|string',
            'state' => 'sometimes|boolean',
            'business_ids' => 'sometimes|array',
            'business_ids.*' => 'integer|exists:business,busines_id',
        ]);

        DB::transaction(function () use ($datos, $owner, $id) {
            $cuenta = array_filter([
                'name' => $datos['name'] ?? null,
                'email' => $datos['email'] ?? null,
                'phone' => array_key_exists('phone', $datos) ? $datos['phone'] : null,
                'address' => array_key_exists('address', $datos) ? $datos['address'] : null,
            ], fn ($v) => $v !== null);

            if (array_key_exists('state', $datos)) {
                $cuenta['state'] = (int) $datos['state'];
            }
            if (! empty($datos['password'])) {
                $cuenta['password'] = Hash::make($datos['password']);
            }
            if ($cuenta) {
                DB::table('user')->where('user_id', $owner->user_id)->update($cuenta);
            }

            $ficha = [];
            foreach (['document_type_id', 'document_number', 'birthdate', 'contact_secondary', 'notes'] as $campo) {
                if (array_key_exists($campo, $datos)) {
                    $ficha[$campo] = $datos[$campo];
                }
            }
            if (array_key_exists('state', $datos)) {
                $ficha['state'] = (int) $datos['state'];
            }
            if ($ficha) {
                DB::table('owner')->where('owner_id', $id)->update($ficha);
            }

            if (array_key_exists('business_ids', $datos)) {
                $this->asignarNegocios((int) $id, $datos['business_ids']);
            }
        });

        return response()->json(['message' => 'Propietario actualizado.']);
    }

    /**
     * Reescribe qué negocios administra un propietario.
     *
     * Un negocio tiene un dueño y solo uno: al asignarlo se retira de quien lo
     * tuviera antes, o quedaría con dos administradores y ninguno de los dos
     * sabría por qué ve pedidos ajenos.
     */
    private function asignarNegocios(int $ownerId, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::table('owner_busines')->where('owner_id', $ownerId)->delete();

        foreach ($ids as $negocio) {
            DB::table('owner_busines')->where('busines_id', $negocio)->delete();
            DB::table('owner_busines')->insert([
                'owner_id' => $ownerId,
                'busines_id' => $negocio,
                'state' => 1,
            ]);
        }
    }

    /* ==================================================================
       CONVERSACIONES
       ================================================================== */
}
