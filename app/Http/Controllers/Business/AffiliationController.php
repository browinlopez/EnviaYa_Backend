<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\AfiliarClienteRequest;
use App\Http\Requests\Business\AlternarAfiliacionRequest;
use App\Http\Requests\Business\BuscarCompradorPorTelefonoRequest;
use App\Http\Requests\Business\ClientesAfiliadosRequest;
use App\Http\Requests\Business\DesafiliarClienteRequest;
use App\Models\Business\BusinessUserAffiliation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliationController extends Controller
{
    use ComprobarPertenencia;

    /**
     * Afiliarse a una tienda es cosa de dos: el comprador puede hacerlo por su
     * cuenta desde la ficha de la tienda —con el tope de tres—, y el tendero
     * puede hacerlo por él desde su lista de afiliados.
     *
     * Lo que no puede nadie es afiliar o desafiliar a OTRA persona en una
     * tienda que no es suya: vaciarle los clientes a la competencia o meterle
     * gente. Cerrar también la auto-afiliación dejó a la app publicada con un
     * botón «Afiliar negocio» que respondía 403 siempre.
     */
    private function negarAfiliacionAjena(Request $request): ?JsonResponse
    {
        if ((int) $request->user()?->user_id === (int) $request->user_id) {
            return null;
        }

        return $this->negarNegocioAjeno($request, $request->busines_id);
    }

    // Afiliar o desafiliar
    public function toggle(AlternarAfiliacionRequest $request)
    {

        /*
         * El negocio del cuerpo tiene que ser suyo. Sin esto se afiliaba y
         * desafiliaba gente a tiendas ajenas, y se listaban sus clientes.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->busines_id)) {
            return $no;
        }

        $userId = $request->user_id;
        $businessId = $request->busines_id;

        $affiliation = BusinessUserAffiliation::where('user_id', $userId)
            ->where('busines_id', $businessId)
            ->first();

        if ($affiliation) {
            $affiliation->delete();

            return response()->json(['message' => 'Usuario desafiliado']);
        } else {
            BusinessUserAffiliation::create([
                'user_id' => $userId,
                'busines_id' => $businessId,
            ]);

            return response()->json(['message' => 'Usuario afiliado']);
        }
    }

    // Buscar comprador por número de teléfono y rol
    /** Lo que mide un móvil en Colombia; lo que venga delante es indicativo. */
    private const LARGO_DEL_MOVIL = 10;

    public function searchBuyerByPhone(BuscarCompradorPorTelefonoRequest $request)
    {

        /*
         * SE COMPARAN DÍGITOS, NO TEXTO.
         *
         * Esto era `where('phone', $phone)`: una comparación exacta de cadenas.
         * Y los teléfonos están guardados CON ESPACIOS --«308 291 2460»--
         * mientras que la pantalla que usa esta búsqueda pide el número
         * seguido; su propio marcador de posición dice «3001234567». O sea
         * que la app pedía el número de una forma y el servidor sólo aceptaba
         * la otra: el tendero escribía bien el número de su cliente y le
         * salía «no encontramos a nadie».
         *
         * No vale normalizar sólo lo que llega: lo guardado también viene
         * sucio y en varias formas. Se limpian los dos lados.
         *
         * Se comparan los DIEZ ÚLTIMOS dígitos, que es lo que mide un móvil
         * colombiano: así «+57 308 291 2460», «57 3082912460» y «3082912460»
         * encuentran a la misma persona. Lo de delante es el indicativo.
         */
        $digitos = preg_replace('/\D+/', '', (string) $request->phone);
        $ultimos = substr($digitos, -self::LARGO_DEL_MOVIL);

        /* `SUBSTR(x, -10)` se comporta igual en MySQL y en SQLite, que es
           sobre lo que corre la suite; `RIGHT()` sólo existe en MySQL. */
        $limpio = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";

        $user = User::with(['buyer'])
            ->whereRaw("SUBSTR({$limpio}, -".self::LARGO_DEL_MOVIL.') = ?', [$ultimos])
            ->where('rol', 1) // Filtrar por rol igual a 1
            ->whereHas('buyer') // Solo los que tienen relación con comprador
            ->first();

        if (! $user) {
            return response()->json(['message' => 'No se encontró comprador con ese número y rol'], 404);
        }

        /*
         * SE DEVUELVE LO MÍNIMO, y es un arreglo de seguridad, no estética.
         *
         * Antes salía el modelo `User` entero. Acá hay una persona que NO es
         * cliente de quien pregunta —todavía— y a la que se está encontrando
         * por un número de teléfono, así que con la cuenta de cualquiera se
         * podían ir probando números y sacar de cada uno su dirección, su
         * correo, la IP desde la que aceptó la política de datos y —lo más
         * grave— su `email_verification_token`, que es con lo que se verifica
         * un correo ajeno.
         *
         * Para decidir «sí, es esta persona» hacen falta el nombre y el
         * correo, que es lo que enseña la pantalla. Nada más.
         */
        return response()->json([
            'user' => [
                'user_id' => (int) $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'buyer' => $user->buyer ? [
                'buyer_id' => (int) $user->buyer->buyer_id,
                'user_id' => (int) $user->buyer->user_id,
                'qualification' => $user->buyer->qualification,
            ] : null,
        ]);
    }

    // Afiliacion movil usuarios
    public function AfiliationUser(AfiliarClienteRequest $request)
    {

        if ($no = $this->negarAfiliacionAjena($request)) {
            return $no;
        }

        $userId = $request->user_id;
        $businessId = $request->busines_id;

        // 1️⃣ Verificar si ya está afiliado a este negocio
        $alreadyAffiliated = BusinessUserAffiliation::where('user_id', $userId)
            ->where('busines_id', $businessId)
            ->exists();

        if ($alreadyAffiliated) {
            return response()->json([
                'message' => 'El usuario ya está afiliado a este negocio',
            ], 409);
        }

        // 2️⃣ Contar afiliaciones actuales del usuario
        $affiliationsCount = BusinessUserAffiliation::where('user_id', $userId)->count();

        if ($affiliationsCount >= 3) {
            return response()->json([
                'message' => 'El usuario ya alcanzó el máximo de 3 negocios afiliados',
            ], 422);
        }

        // 3️⃣ Crear afiliación
        $affiliation = BusinessUserAffiliation::create([
            'user_id' => $userId,
            'busines_id' => $businessId,
        ]);

        return response()->json([
            'message' => 'Usuario afiliado correctamente',
            'affiliation' => $affiliation,
        ], 201);
    }

    // desafiliar usuario movil
    public function DesafiliationUser(DesafiliarClienteRequest $request)
    {

        if ($no = $this->negarAfiliacionAjena($request)) {
            return $no;
        }

        $affiliation = BusinessUserAffiliation::where('user_id', $request->user_id)
            ->where('busines_id', $request->busines_id)
            ->first();

        if (! $affiliation) {
            return response()->json([
                'message' => 'El usuario no está afiliado a este negocio',
            ], 404);
        }

        $affiliation->delete();

        return response()->json([
            'message' => 'Usuario desafiliado correctamente',
        ]);
    }

    // Listar todos los usuarios afiliados a un negocio
    public function getAffiliatedUsers(ClientesAfiliadosRequest $request)
    {

        /*
         * El negocio del cuerpo tiene que ser suyo. Sin esto se afiliaba y
         * desafiliaba gente a tiendas ajenas, y se listaban sus clientes.
         */
        if ($no = $this->negarNegocioAjeno($request, $request->busines_id)) {
            return $no;
        }

        $businessId = $request->busines_id;

        $affiliations = BusinessUserAffiliation::where('busines_id', $businessId)
            ->with([
                'user' => function ($q) {
                    $q->select('user_id', 'name', 'email', 'phone', 'rol', 'qualification', 'state')
                        ->with([
                            'buyer:buyer_id,user_id,qualification,state,belongs_to_complex',
                            'domiciliary:domiciliary_id,user_id,available,document,qualification,state',
                            'rolRelation:rol_id,name',
                        ]);
                },
            ])
            ->get();

        $formatted = $affiliations->map(function ($aff) {
            return [
                'affiliation_id' => $aff->id,
                'user' => [
                    'user_id' => $aff->user->user_id,
                    'name' => $aff->user->name,
                    'email' => $aff->user->email,
                    'phone' => $aff->user->phone,
                    'rol' => $aff->user->rolRelation->name ?? null,
                    'qualification' => $aff->user->qualification,
                    'state' => $aff->user->state,
                    'buyer' => $aff->user->buyer ? [
                        'buyer_id' => $aff->user->buyer->buyer_id,
                        'qualification' => $aff->user->buyer->qualification,
                        'belongs_to_complex' => $aff->user->buyer->belongs_to_complex,
                        'state' => $aff->user->buyer->state,
                    ] : null,
                    'domiciliary' => $aff->user->domiciliary ? [
                        'domiciliary_id' => $aff->user->domiciliary->domiciliary_id,
                        'document' => $aff->user->domiciliary->document,
                        'available' => $aff->user->domiciliary->available,
                        'qualification' => $aff->user->domiciliary->qualification,
                        'state' => $aff->user->domiciliary->state,
                    ] : null,
                ],
            ];
        });

        return response()->json([
            'affiliations' => $formatted,
            'count' => $formatted->count(),
        ]);
    }
}
