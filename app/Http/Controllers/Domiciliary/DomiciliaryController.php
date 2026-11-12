<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Domiciliary\ActualizarDomiciliarioRequest;
use App\Http\Requests\Domiciliary\CrearDomiciliarioRequest;
use App\Http\Requests\Domiciliary\DomiciliariosDeUnNegocioRequest;
use App\Http\Requests\Domiciliary\VerDomiciliarioRequest;
use App\Http\Resources\Usuario\UsuarioResource;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\User;
use App\Services\Ajustes;
use App\Services\CustodiaDeEfectivo;
use App\Services\PapelesDelDomiciliario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DomiciliaryController extends Controller
{
    // Listar todos los domiciliarios de un negocio
    public function listDomiciliariesByBusiness(DomiciliariosDeUnNegocioRequest $request)
    {

        $business = Business::with(['domiciliaries.user'])
            ->findOrFail($request->busines_id);

        // Pedidos que cada domiciliario lleva encima ahora mismo, para que el
        // tendero vea a quién puede despacharle antes de intentarlo.
        $business->domiciliaries->loadCount([
            'orders as active_orders' => fn ($q) => $q->where('state', 3),
        ]);

        $maxSimultaneos = (int) Ajustes::valor('operacion.entregas_simultaneas');

        /*
         * Cuánto efectivo lleva encima cada uno.
         *
         * El tendero lo necesita ANTES de despachar: si el negocio puso tope y
         * el pedido es contra entrega, el servidor va a rechazar la asignación.
         * Sin este dato, el tendero lo intenta, recibe un error y no entiende
         * por qué — la lista le decía que esa persona estaba disponible.
         */
        $custodia = app(CustodiaDeEfectivo::class);
        $tope = $business->max_courier_cash !== null
            ? (float) $business->max_courier_cash
            : null;

        $papeles = app(PapelesDelDomiciliario::class);

        $domiciliaries = $business->domiciliaries->map(function ($domiciliary) use ($maxSimultaneos, $custodia, $tope, $papeles) {
            $enCurso = (int) ($domiciliary->active_orders ?? 0);
            $disponible = (bool) $domiciliary->available;
            // Lo que le falta para repartir, si lo dio de alta una tienda.
            $faltan = $papeles->faltantes($domiciliary);
            $efectivo = round($custodia->saldo($domiciliary->domiciliary_id), 2);

            return [
                'domiciliary_id' => $domiciliary->domiciliary_id,
                // user_id del domiciliario: requerido para despachar una
                // orden (transición 2 → 3 en orders/update)
                'user_id' => $domiciliary->user_id,
                'name' => $domiciliary->user ? $domiciliary->user->name : null,
                'email' => $domiciliary->user ? $domiciliary->user->email : null,
                'phone' => $domiciliary->user ? $domiciliary->user->phone : null,
                'available' => $domiciliary->available,
                'qualification' => $domiciliary->qualification,
                'state' => $domiciliary->state,
                'active_orders' => $enCurso,
                'max_active_orders' => $maxSimultaneos,
                // Mismas condiciones que valida orders/update al despachar.
                'can_take' => $disponible && $enCurso < $maxSimultaneos && ! $faltan,
                'papeles_faltantes' => $faltan,
                'cash_on_hand' => $efectivo,
                'cash_limit' => $tope,
                /*
                 * Cuánto le cabe todavía en efectivo. Se manda calculado y no
                 * se deja al cliente: la app y el panel harían la misma resta
                 * cada uno por su lado, y el día que el tope cambie de forma
                 * habría que acordarse de los dos.
                 */
                'cash_room' => $tope === null ? null : max(0, round($tope - $efectivo, 2)),
            ];
        });

        return response()->json([
            'busines_id' => $business->busines_id,
            'business_name' => $business->name,
            'domiciliaries' => $domiciliaries,
        ]);
    }

    // Crear un domiciliario
    public function createDomiciliary(CrearDomiciliarioRequest $request)
    {

        try {

            DB::beginTransaction();

            // Crear usuario
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'address' => $request->address,
                'rol' => 3,
                'qualification' => $request->qualification ?? 0,
                'state' => $request->state,
            ]);

            // Crear domiciliario asociado
            $domiciliary = Domiciliary::create([
                'user_id' => $user->user_id,
                'available' => $request->available ?? false,
                'qualification' => $user->qualification,
                'state' => $user->state,
                'document' => $request->document ?? null,   // si tienes este campo
            ]);

            // Vincularlo al negocio de quien lo está creando. Sin esto el
            // domiciliario quedaba "suelto": no aparecía en el listado de la
            // tienda ni en el selector al despachar un pedido, y el tendero
            // tampoco podía editarlo.
            $negocio = $this->negocioDelCreador($request);

            if ($negocio) {
                $domiciliary->businesses()->syncWithoutDetaching([
                    $negocio => ['state' => 1],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Usuario y domiciliario creados correctamente',
                'user' => new UsuarioResource($user),
                'domiciliary' => $domiciliary->load('businesses'),
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Error al crear el domiciliario: '.$e->getMessage(),
            ], 500);
        }
    }

    // Actualizar un domiciliario
    public function updateDomiciliary(ActualizarDomiciliarioRequest $request)
    {

        // Buscar usuario
        $user = User::findOrFail($request->user_id);

        // Solo puede editar el propio domiciliario o el dueño de un negocio al
        // que esté asignado. Antes cualquier sesión válida podía modificar a
        // cualquier usuario mandando su user_id.
        if (! $this->puedeEditarDomiciliario($request->user(), $user)) {
            return response()->json([
                'message' => 'No tienes permiso para editar este domiciliario',
            ], 403);
        }

        // Actualizar usuario
        $user->update($request->only([
            'name',
            'email',
            'phone',
            'address',
        ]));

        // Obtener y actualizar domiciliario relacionado.
        // `qualification` se excluye a propósito: la calificación la producen
        // las reseñas de los compradores, no puede fijarse a mano desde aquí.
        $domiciliary = $user->domiciliary;
        if ($domiciliary) {
            $domiciliary->update($request->only([
                'available',
                'state',
            ]));
        }

        return response()->json([
            'message' => 'Información actualizada correctamente',
            'user' => new UsuarioResource($user),
            'domiciliary' => $domiciliary,
        ]);
    }

    /**
     * Negocio al que se vincula un domiciliario recién creado: el indicado en
     * la petición si pertenece a quien crea, o su primer negocio.
     */
    private function negocioDelCreador(Request $request): ?int
    {
        $owner = $request->user()?->owner;

        if (! $owner) {
            return null;
        }

        $propios = $owner->businesses->pluck('busines_id');

        if ($request->filled('busines_id')) {
            return $propios->contains((int) $request->busines_id)
                ? (int) $request->busines_id
                : null;
        }

        return $propios->first();
    }

    /**
     * Un domiciliario puede editarse a sí mismo; un dueño de negocio puede
     * editar a los domiciliarios asignados a alguno de sus negocios.
     */
    private function puedeEditarDomiciliario($autenticado, User $objetivo): bool
    {
        if (! $autenticado) {
            return false;
        }

        if ((int) $autenticado->user_id === (int) $objetivo->user_id) {
            return true;
        }

        $domiciliary = $objetivo->domiciliary;

        if (! $domiciliary || ! $autenticado->owner) {
            return false;
        }

        $negociosPropios = $autenticado->owner->businesses->pluck('busines_id');

        return $domiciliary->businesses()
            ->whereIn('business.busines_id', $negociosPropios)
            ->exists();
    }

    // Obtener un domiciliario específico
    public function showDomiciliary(VerDomiciliarioRequest $request)
    {

        $domiciliary = Domiciliary::with('user', 'reviews')->find($request->domiciliary_id);

        return response()->json($domiciliary);
    }
}
