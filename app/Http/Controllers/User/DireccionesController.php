<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Concerns\ComprobarPertenencia;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\User;
use App\Models\User\UserAddress;
use App\Models\Notification;
use App\Services\MunicipioPorNombre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class DireccionesController extends Controller
{
    use ComprobarPertenencia;

    // Obtener direcciones del usuario con jerarquía completa
    public function getAddresses(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

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
                'municipality.department.country:id,name,iso_code'
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
    public function addAddress(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
            'address' => 'required|string|max:225',
            /*
             * La calle que escribe la persona. La app ya la mandaba y acá no se
             * miraba: se perdía en silencio.
             *
             * Es opcional porque la dirección del mapa por sí sola ya sirve
             * para pedir; pero cuando viene, es la que lleva el número de casa
             * y la que de verdad permite llegar a la puerta.
             */
            'street' => 'nullable|string|max:150',
            /*
             * Dónde vive quien está en un conjunto.
             *
             * Van como texto y no como número: hay conjuntos con "Torre A" y
             * apartamentos como "502B". Guardarlos como enteros obligaría a
             * inventar una traducción y perdería lo que la persona escribió.
             */
            'complex_id' => 'nullable|integer|exists:residential_complexes,complex_id',
            'tower' => 'nullable|string|max:40',
            'apartment' => 'nullable|string|max:40',
            /*
             * El municipio llega por NOMBRE, no por identificador.
             *
             * El teléfono resuelve las coordenadas a ciudad y departamento para
             * escribir la dirección en pantalla; no conoce nuestros ids. Antes
             * esto pedía `municipality_id` y estaba comentado, así que ninguna
             * dirección creada desde la app tenía municipio.
             *
             * Se sigue admitiendo el id por si algún día lo manda el panel.
             */
            'municipality' => 'nullable|string|max:120',
            'department' => 'nullable|string|max:120',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'alias_id' => 'nullable|integer|exists:alias,alias_id',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

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
            'state' => true
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
    public function deleteAddress(Request $request, $id)
    {
        $request->validate([
            'user_id' => 'required|integer|exists:user,user_id',
        ]);

        // Sin esto se borraba la direccion de cualquiera pasando su user_id.
        if ($no = $this->negarCuentaAjena($request, $request->user_id)) {
            return $no;
        }

        $address = UserAddress::where('address_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$address) {
            return response()->json([
                'message' => 'La dirección no existe o no es tuya.',
            ], 404);
        }

        $address->update(['state' => false]);

        return response()->json(['message' => 'Dirección eliminada.']);
    }
}
