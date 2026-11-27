<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domiciliary;

use App\Http\Controllers\Controller;
use App\Http\Requests\Domiciliary\ActualizarDomiciliarioRequest;
use App\Http\Requests\Domiciliary\CrearDomiciliarioRequest;
use App\Http\Requests\Domiciliary\DomiciliariosDeUnNegocioRequest;
use App\Http\Requests\Domiciliary\VerDomiciliarioRequest;
use App\Http\Resources\Domiciliario\DomiciliarioDeLaFlotaResource;
use App\Http\Resources\Domiciliario\MiFichaDeDomiciliarioResource;
use App\Http\Resources\Usuario\DomiciliarioDelAltaResource;
use App\Http\Resources\Usuario\UsuarioResource;
use App\Models\Business;
use App\Models\Domiciliary;
use App\Models\Order\OrdersSales;
use App\Models\User;
use App\Services\Ajustes;
use App\Services\CustodiaDeEfectivo;
use App\Services\PapelesDelDomiciliario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            'orders as active_orders' => fn ($q) => $q->where('state', OrdersSales::EN_CAMINO),
            // Los de esta tienda: «en la calle» cuadra con la Torre.
            'orders as en_camino_tuyos' => fn ($q) => $q->where('state', OrdersSales::EN_CAMINO)
                ->where('busines_id', $business->busines_id),
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

            return (new DomiciliarioDeLaFlotaResource(
                $domiciliary,
                enCurso: $enCurso,
                maxSimultaneos: $maxSimultaneos,
                // Mismas condiciones que valida orders/update al despachar.
                puedeTomar: $disponible && $enCurso < $maxSimultaneos && ! $faltan,
                papelesFaltantes: $faltan,
                efectivo: $efectivo,
                tope: $tope,
            ))->resolve();
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
                /*
                 * EL DEFECTO, QUE FALTABA. `CrearDomiciliarioRequest` declara
                 * `state` OPCIONAL --`'state' => 'boolean'`--, asi que el
                 * panel puede no mandarlo; y entonces esto escribia `null`.
                 *
                 * En SQLite pasa, porque la migracion original creo la columna
                 * `nullable()`. En MySQL NO: `estados_sin_null_y_pivotes_unicos`
                 * hace `MODIFY state TINYINT NOT NULL DEFAULT 1` y lo hace SOLO
                 * si el motor es mysql. O sea que dar de alta un repartidor sin
                 * mandar `state` era un 500 en produccion y verde en la suite.
                 * El `1` es el mismo defecto que declara esa migracion.
                 *
                 * La linea de arriba ya ponia su defecto; esta se quedo sin el.
                 */
                'state' => $request->state ?? 1,
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
            /*
             * ESTE `catch` SE QUEDA ATRAPANDO TODO, PORQUE ESTA PARA REVERTIR.
             *
             * Lo que no se queda es la respuesta que daba: un 500 con
             * `'error' => 'Error al crear el domiciliario: '.$e->getMessage()`.
             * Eso ponia el texto crudo de la excepcion --nombres de columna,
             * trozos de SQL, rutas del servidor-- en la pantalla de quien
             * estaba dando de alta a un repartidor, y ademas la averia NO
             * llegaba a Sentry, porque la excepcion moria aqui.
             *
             * Se revierte y se vuelve a lanzar: el manejador de Laravel la
             * reporta y devuelve el 500 sin contar de mas.
             */
            DB::rollBack();

            throw $e;
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
        /* La regla vive en `DomiciliaryPolicy::verYEditar`, por la puerta con
           nombre: el objetivo puede no tener fila de domiciliario y la regla
           dice que uno siempre puede verse a si mismo, incluso entonces. */
        if (Gate::denies('ver-y-editar-domiciliario', [$user])) {
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
            'domiciliary' => (new DomiciliarioDelAltaResource($domiciliary))->resolve(),
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

    /*
     * `puedeVerYEditarDomiciliario()` SE MUDO a
     * `App\Policies\DomiciliaryPolicy::verYEditar` el 14 de noviembre de
     * 2026, sin cambiar una coma. §08 pide Policy por recurso; aqui era un
     * metodo privado, o sea una regla de autorizacion que solo podia usar
     * quien la escribio --y por eso `showDomiciliary` se quedo sin acotar
     * mientras `updateDomiciliary` si lo estaba--.
     */

    /**
     * La ficha de un domiciliario: la suya, o la de uno de su tienda.
     *
     * LO QUE ESTO ARREGLA. El `domiciliary_id` llegaba EN EL CUERPO de la
     * petición y nada lo comparaba con quien preguntaba; encima del grupo
     * `domiciliaries` solo hay `auth:sanctum`. Medido contra la pila HTTP: un
     * COMPRADOR con sesión recibía 200, la cédula del domiciliario, su correo,
     * su teléfono y —lo que menos se veía— el `email_verification_token` de su
     * usuario, que es con lo que se verifica un correo.
     *
     * No era una fuga de diseño, era un olvido con fecha: `updateDomiciliary`
     * se acotó por este mismo motivo y esta, cuarenta líneas más arriba en el
     * archivo, no. Las dos usan ahora el mismo predicado.
     *
     * EL IDENTIFICADOR PASA A SER OPCIONAL, y eso es lo que hace que esto sea
     * seguro de verdad y no solo comprobado: si no viene, el domiciliario se
     * resuelve de LA SESIÓN, así que el cliente no tiene que mandar un número
     * que el servidor ya sabe. La app dejó de mandarlo.
     *
     * SIGUE ACEPTÁNDOLO para no romper lo que ya está instalado: las versiones
     * publicadas mandan el suyo, que es el mismo que resolvería la sesión, así
     * que para ellas no cambia nada. Cuando llega uno ajeno es cuando responde
     * 403, y antes respondía la ficha.
     */
    public function showDomiciliary(VerDomiciliarioRequest $request)
    {
        $autenticado = $request->user();

        /*
         * Sin identificador, el de la sesión. `?->` y no `->`: quien pregunta
         * puede no ser domiciliario, y entonces no hay ficha propia que dar.
         */
        $id = $request->filled('domiciliary_id')
            ? (int) $request->domiciliary_id
            : $autenticado?->domiciliary?->domiciliary_id;

        if ($id === null) {
            return response()->json([
                'message' => 'Indica de qué domiciliario quieres la ficha.',
            ], 422);
        }

        $domiciliary = Domiciliary::with('user', 'reviews')->find($id);

        /*
         * 404 y no `null` con 200, que es lo que hacía: `find()` sobre un id
         * que no está devuelve `null` y `response()->json(null)` es un 200 con
         * cuerpo `null`. La validación lo tapaba con un 422 mientras el campo
         * era obligatorio; ahora que puede faltar, el camino existe.
         */
        if (! $domiciliary) {
            return response()->json(['message' => 'El domiciliario no existe.'], 404);
        }

        /*
         * `user_id` es nulable en `domiciliary`, así que una fila huérfana no
         * tiene con quién compararse. Nadie la ve: sin usuario detrás no hay
         * «es el propio» ni «es de mi tienda» que comprobar, y adivinar sería
         * justo lo que esto viene a cerrar.
         */
        if ($domiciliary->user === null || Gate::denies('ver', $domiciliary)) {
            return response()->json([
                'message' => 'No tienes permiso para ver este domiciliario',
            ], 403);
        }

        return response()->json(
            (new MiFichaDeDomiciliarioResource($domiciliary))->resolve()
        );
    }
}
