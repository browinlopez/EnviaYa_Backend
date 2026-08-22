<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\ResidentialComplex;

/**
 * LOS CONJUNTOS QUE LA APP OFRECE AL REGISTRARSE
 *
 * Es PÚBLICO porque se necesita ANTES de tener cuenta: la pantalla de registro
 * pregunta si vives en un conjunto y hasta ahora no tenía de dónde sacar la
 * lista. Las cuatro rutas de conjuntos que ya existían viven bajo `/admin` y
 * exigen rol 4, así que un comprador no podía consultarlas — y la app acabó
 * con cuatro nombres inventados escritos a mano en el código.
 *
 * Eso no era solo cosmético: los identificadores quemados eran 1 a 4, el 1 no
 * existe en la base y el 2, 3 y 4 son conjuntos con OTROS nombres. Quien elegía
 * el primero recibía un 422 y no podía crear su cuenta; quien elegía el segundo
 * quedaba inscrito en un conjunto distinto del que había señalado.
 *
 * Devuelve lo justo para pintar un selector. Nada de `people_count`, que es
 * información de negocio y no le hace falta a nadie para registrarse.
 */
class ResidentialComplexController extends Controller
{
    public function index()
    {
        $conjuntos = ResidentialComplex::query()
            ->where('state', 1)
            ->orderBy('name')
            /*
             * Van también las torres y los apartamentos por torre: es lo que
             * permite que el registro de la app ofrezca listas en vez de pedir
             * que la persona escriba "Torre 3" a mano y se equivoque.
             */
            ->get([
                'complex_id', 'name', 'address', 'municipality_id',
                'towers_count', 'apartments_per_tower',
            ]);

        return response()->json([
            'status'  => true,
            'message' => 'Conjuntos disponibles',
            'data'    => $conjuntos,
        ]);
    }
}
