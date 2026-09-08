<?php

namespace App\Http\Controllers\Admin\Api;

use App\Support\Consultas\AyudasDeAdmin;
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

class PropietariosApiController extends Controller
{
    use AyudasDeAdmin;


    public function owners()
    {
        $propietarios = DB::table('owner as o')
            ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
            // `document_types` usa `id` como llave y guarda el rótulo en
            // `name_es` / `name_en`; no tiene columna `name`.
            ->leftJoin('document_types as dt', 'dt.id', '=', 'o.document_type_id')
            ->orderBy('u.name')
            ->get([
                'o.owner_id', 'o.user_id', 'o.document_type_id', 'o.document_number',
                'o.birthdate', 'o.contact_secondary', 'o.notes', 'o.state', 'o.profile_photo',
                'u.name', 'u.email', 'u.phone', 'u.address',
                'u.state as account_state',
                'dt.code as document_type',
            ]);

        // Los negocios se traen en una sola consulta y se reparten en
        // memoria: un join dejaría una fila por negocio y habría que
        // desduplicar el propietario.
        $negocios = DB::table('owner_busines as ob')
            ->leftJoin('business as b', 'b.busines_id', '=', 'ob.busines_id')
            ->leftJoin('orderssales as o', function ($j) {
                $j->on('o.busines_id', '=', 'b.busines_id')->where('o.state', '=', self::ENTREGADO);
            })
            ->groupBy('ob.owner_id', 'b.busines_id', 'b.name')
            ->get([
                'ob.owner_id', 'b.busines_id', 'b.name',
                DB::raw('COALESCE(SUM(o.total), 0) as revenue'),
            ])
            ->groupBy('owner_id');

        return response()->json(
            $propietarios->map(function ($p) use ($negocios) {
                $suyos = $negocios[$p->owner_id] ?? collect();
                $p->businesses = $suyos->values();
                $p->businesses_count = $suyos->count();
                $p->business_ids = $suyos->pluck('busines_id')->filter()->values();
                $p->revenue = round($suyos->sum('revenue'), 2);
                return $p;
            })
        );
    }

    /** Tipos de documento y negocios libres, para los selectores de la ficha. */
    public function ownerOptions()
    {
        return response()->json([
            'document_types' => DB::table('document_types')
                ->orderBy('id')
                ->get(['id', 'code', DB::raw('name_es as name')]),
            'businesses' => DB::table('business as b')
                ->leftJoin('owner_busines as ob', 'ob.busines_id', '=', 'b.busines_id')
                ->leftJoin('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                ->leftJoin('user as u', 'u.user_id', '=', 'o.user_id')
                ->orderBy('b.name')
                ->get([
                    'b.busines_id', 'b.name', 'b.address', 'b.state',
                    'ob.owner_id as current_owner_id',
                    'u.name as current_owner_name',
                ]),
        ]);
    }

    /**
     * Crea un propietario: la cuenta de usuario (rol 2) y su ficha `owner`.
     *
     * Se hace en una transacción porque un usuario sin fila en `owner` puede
     * entrar a la app pero no administrar nada, y eso es peor que no existir.
     */
    public function storeOwner(Request $request)
    {
        $datos = $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|max:255|unique:user,email',
            'password'          => 'required|string|min:6',
            'phone'             => 'nullable|string|max:20',
            'address'           => 'nullable|string|max:255',
            'document_type_id'  => 'nullable|integer|exists:document_types,id',
            'document_number'   => 'nullable|string|max:50',
            'birthdate'         => 'nullable|date',
            // La columna es varchar(45): validar con un tope mayor solo
            // cambiaría el 422 por un error de truncado en la base.
            'contact_secondary' => 'nullable|string|max:45',
            'notes'             => 'nullable|string',
            'state'             => 'sometimes|boolean',
            'business_ids'      => 'sometimes|array',
            'business_ids.*'    => 'integer|exists:business,busines_id',
        ]);

        $ownerId = DB::transaction(function () use ($datos) {
            $userId = DB::table('user')->insertGetId([
                'name'              => $datos['name'],
                'email'             => $datos['email'],
                'password'          => Hash::make($datos['password']),
                'phone'             => $datos['phone'] ?? null,
                'address'           => $datos['address'] ?? null,
                'rol'               => 2,
                'state'             => (int) ($datos['state'] ?? 1),
                'qualification'     => 0,
                // Lo da de alta un administrador: sin verificar, el login lo
                // rechazaría y la cuenta nacería inservible.
                'email_verified_at' => now(),
                // La fecha la pone la aplicación y no la base: `CURRENT_TIMESTAMP`
                // es el reloj del servidor, que en el VPS no es el de Bogotá.
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $ownerId = DB::table('owner')->insertGetId([
                'user_id'           => $userId,
                'document_type_id'  => $datos['document_type_id'] ?? null,
                'document_number'   => $datos['document_number'] ?? null,
                'birthdate'         => $datos['birthdate'] ?? null,
                'contact_secondary' => $datos['contact_secondary'] ?? null,
                'notes'             => $datos['notes'] ?? null,
                'state'             => (int) ($datos['state'] ?? 1),
            ]);

            $this->asignarNegocios($ownerId, $datos['business_ids'] ?? []);

            return $ownerId;
        });

        return response()->json(['message' => 'Propietario creado.', 'owner_id' => $ownerId], 201);
    }

    public function updateOwner(Request $request, $id)
    {
        $owner = DB::table('owner')->where('owner_id', $id)->first();
        abort_if(!$owner, 404, 'El propietario no existe.');

        $datos = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'email'             => ['sometimes', 'email', 'max:255', Rule::unique('user', 'email')->ignore($owner->user_id, 'user_id')],
            'password'          => 'sometimes|nullable|string|min:6',
            'phone'             => 'sometimes|nullable|string|max:20',
            'address'           => 'sometimes|nullable|string|max:255',
            'document_type_id'  => 'sometimes|nullable|integer|exists:document_types,id',
            'document_number'   => 'sometimes|nullable|string|max:50',
            'birthdate'         => 'sometimes|nullable|date',
            'contact_secondary' => 'sometimes|nullable|string|max:45',
            'notes'             => 'sometimes|nullable|string',
            'state'             => 'sometimes|boolean',
            'business_ids'      => 'sometimes|array',
            'business_ids.*'    => 'integer|exists:business,busines_id',
        ]);

        DB::transaction(function () use ($datos, $owner, $id) {
            $cuenta = array_filter([
                'name'    => $datos['name'] ?? null,
                'email'   => $datos['email'] ?? null,
                'phone'   => array_key_exists('phone', $datos) ? $datos['phone'] : null,
                'address' => array_key_exists('address', $datos) ? $datos['address'] : null,
            ], fn($v) => $v !== null);

            if (array_key_exists('state', $datos)) {
                $cuenta['state'] = (int) $datos['state'];
            }
            if (!empty($datos['password'])) {
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
                'owner_id'   => $ownerId,
                'busines_id' => $negocio,
                'state'      => 1,
            ]);
        }
    }

    /* ==================================================================
       CONVERSACIONES
       ================================================================== */
}
