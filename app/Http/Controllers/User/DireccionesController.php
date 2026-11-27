<?php

declare(strict_types=1);

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\AgregarDireccionRequest;
use App\Http\Requests\User\BorrarDireccionRequest;
use App\Http\Requests\User\DireccionesDelUsuarioRequest;
use App\Http\Resources\Usuario\DireccionResource;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerComplex;
use App\Models\Buyer\ResidentialComplex;
use App\Models\User\UserAddress;
use App\Services\MunicipioPorNombre;

class DireccionesController extends Controller
{
    use ComprobarPertenencia;

    // Obtener direcciones del usuario con jerarquía completa
    public function getAddresses(DireccionesDelUsuarioRequest $request)
    {

        /*
         * La direccion de la casa de otra persona, con coordenadas.
         *
         * `user_id` llegaba en el cuerpo y no se comparaba con nadie: con
         * cualquier cuenta se listaban las direcciones de cualquiera, y los
         * identificadores son correlativos.
         */
        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        $addresses = UserAddress::where('user_id', $request->user_id)
            ->where('state', true)
            ->with([
                'alias:alias_id,name',
                'complex:complex_id,name',
                'municipality:id,name,department_id',
                'municipality.department:id,name,country_id',
                'municipality.department.country:id,name,iso_code',
            ])
            ->get()
            ->map(function ($address) {
                return (new DireccionResource($address))->resolve();
            });

        return response()->json($addresses);
    }

    // Agregar dirección a un usuario
    public function addAddress(AgregarDireccionRequest $request)
    {

        /*
         * Si no se reconoce el municipio se guarda igual, sin él.
         *
         * Rechazar la dirección por eso dejaría a alguien sin poder pedir por
         * vivir en un municipio que todavía no está en la tabla —y la dirección
         * del mapa, que es la que usa el domiciliario, ya está completa—.
         */
        $municipioId = $request->municipality_id
            ?? MunicipioPorNombre::resolver($request->municipality, $request->department);

        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        $conjunto = $this->conjuntoDeLaDireccion($request);

        if ($conjunto['complex_id']) {
            $c = ResidentialComplex::find($conjunto['complex_id']);
            if ($c && ! $c->torreValida($conjunto['tower'])) {
                return response()->json([
                    'message' => "{$c->name} tiene {$c->towers_count} torres: revisa el número de la torre.",
                    'errors' => ['tower' => ['Esa torre no existe en el conjunto.']],
                ], 422);
            }
        }

        $address = UserAddress::create([
            'user_id' => $request->user_id,
            'address' => $request->address,
            'street' => $request->street,
            'complex_id' => $conjunto['complex_id'],
            'tower' => $conjunto['tower'],
            'apartment' => $conjunto['apartment'],
            'municipality_id' => $municipioId,
            'alias_id' => $request->alias_id,
            'nombre' => $request->nombre ? trim((string) $request->nombre) : null,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'state' => true,
        ]);

        if ($conjunto['complex_id']) {
            $this->unirAlConjunto((int) $request->user_id, (int) $conjunto['complex_id']);
        }

        return response()->json($address, 201);
    }

    /** A cuántos metros del conjunto se da por hecho que la dirección es la suya. */
    private const CERCA_DEL_CONJUNTO_M = 150;

    // Con «mi ubicación» en casa la dirección llegaba sin conjunto y la portería rechazaba.
    /** @return array{complex_id: int|null, tower: string|null, apartment: string|null} */
    private function conjuntoDeLaDireccion(AgregarDireccionRequest $request): array
    {
        if ($request->complex_id) {
            return [
                'complex_id' => (int) $request->complex_id,
                'tower' => $request->tower,
                'apartment' => $request->apartment,
            ];
        }

        $nada = ['complex_id' => null, 'tower' => null, 'apartment' => null];

        if ($request->latitude === null || $request->longitude === null) {
            return $nada;
        }

        $buyerId = Buyer::where('user_id', $request->user_id)->value('buyer_id');
        if (! $buyerId) {
            return $nada;
        }

        $conjuntos = ResidentialComplex::query()
            ->whereIn('complex_id', BuyerComplex::where('buyer_id', $buyerId)->pluck('complex_id'))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['complex_id', 'latitude', 'longitude']);

        foreach ($conjuntos as $c) {
            $metros = self::metrosEntre(
                (float) $request->latitude, (float) $request->longitude,
                (float) $c->latitude, (float) $c->longitude,
            );

            if ($metros > self::CERCA_DEL_CONJUNTO_M) {
                continue;
            }

            $anterior = UserAddress::where('user_id', $request->user_id)
                ->where('complex_id', $c->complex_id)
                ->whereNotNull('tower')->whereNotNull('apartment')
                ->orderByDesc('address_id')
                ->first(['tower', 'apartment']);

            if ($anterior) {
                return [
                    'complex_id' => (int) $c->complex_id,
                    'tower' => $anterior->tower,
                    'apartment' => $anterior->apartment,
                ];
            }
        }

        return $nada;
    }

    // Quien se muda a un conjunto también debe contar como residente, no solo al registrarse.
    private function unirAlConjunto(int $userId, int $complexId): void
    {
        $buyer = Buyer::where('user_id', $userId)->first();
        if (! $buyer) {
            return;
        }

        BuyerComplex::firstOrCreate(['buyer_id' => $buyer->buyer_id, 'complex_id' => $complexId]);

        if (! $buyer->belongs_to_complex) {
            $buyer->update(['belongs_to_complex' => 1]);
        }
    }

    /** Distancia en metros entre dos puntos (haversine). */
    private static function metrosEntre(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * RETIRAR UNA DIRECCIÓN
     *
     * La app ofrecía el botón de borrar desde hacía tiempo —con su diálogo de
     * confirmación y todo— y al aceptar no ocurría nada, porque este endpoint
     * no existía.
     *
     * Se marca como inactiva en vez de borrarla: los pedidos ya hechos
     * apuntan a ella, y eliminarla de verdad dejaría el histórico sin poder
     * decir a dónde se entregó.
     *
     * La comprobación de pertenencia es lo importante: sin ella, cualquiera
     * con sesión podría retirar la dirección de otra persona probando
     * identificadores.
     */
    public function deleteAddress(BorrarDireccionRequest $request, $id)
    {

        // Sin esto se borraba la direccion de cualquiera pasando su user_id.
        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        $address = UserAddress::where('address_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (! $address) {
            return response()->json([
                'message' => 'La dirección no existe o no es tuya.',
            ], 404);
        }

        $address->update(['state' => false]);

        return response()->json(['message' => 'Dirección eliminada.']);
    }
}
