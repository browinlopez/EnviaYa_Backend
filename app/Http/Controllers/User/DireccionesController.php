<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\AgregarDireccionRequest;
use App\Http\Requests\User\BorrarDireccionRequest;
use App\Http\Requests\User\DireccionesDelUsuarioRequest;
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
                'municipality:id,name,department_id',
                'municipality.department:id,name,country_id',
                'municipality.department.country:id,name,iso_code',
            ])
            ->get()
            ->map(function ($address) {
                return [
                    'address_id' => $address->address_id,
                    'address' => $address->address,
                    'latitude' => $address->latitude,
                    'longitude' => $address->longitude,
                    'alias_id' => $address->alias?->alias_id,
                    'alias_name' => $address->alias?->name,
                    'municipality_id' => $address->municipality?->id,
                    'municipality_name' => $address->municipality?->name,
                    'department_id' => $address->municipality?->department?->id,
                    'department_name' => $address->municipality?->department?->name,
                    'country_id' => $address->municipality?->department?->country?->id,
                    'country_name' => $address->municipality?->department?->country?->name,
                    'country_iso_code' => $address->municipality?->department?->country?->iso_code,
                ];
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

        $address = UserAddress::create([
            'user_id' => $request->user_id,
            'address' => $request->address,
            'street' => $request->street,
            'complex_id' => $request->complex_id,
            'tower' => $request->tower,
            'apartment' => $request->apartment,
            'municipality_id' => $municipioId,
            'alias_id' => $request->alias_id,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'state' => true,
        ]);

        return response()->json($address, 201);
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
