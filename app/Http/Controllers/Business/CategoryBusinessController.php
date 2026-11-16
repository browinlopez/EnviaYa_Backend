<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Http\Resources\Negocio\CategoriaDeNegocioResource;
use App\Models\Business\CategoryBusiness;
use Illuminate\Http\Request;

class CategoryBusinessController extends Controller
{
    public function index(Request $request)
    {
        /*
         * `all()` SE QUEDA, y conviene decir por que, porque es el ultimo del
         * backend y el estandar los senala.
         *
         * `categories_business` es un catalogo CERRADO: un tipo de negocio
         * --tienda, drogueria, panaderia, licorera--, cuatro filas hoy y las
         * que anada el equipo a mano. No crece con el uso, que es lo que hace
         * peligroso un `all()`.
         *
         * Y lo que lo arreglaria de verdad --paginar-- cambiaria la forma de
         * una respuesta que ya leen las apps publicadas, y ordenarlo
         * cambiaria el ORDEN en que la app pinta los tipos de negocio en la
         * pantalla de antes de entrar. Cambiar `all()` por
         * `query()->get()` no arregla nada: lee la tabla entera igual, solo
         * esconde la palabra que el estandar busca.
         *
         * Lo que si era un problema de verdad era la FORMA, y eso es lo que
         * cambia: la lista de claves esta escrita en
         * `CategoriaDeNegocioResource`, porque una de las dos rutas que
         * sirven este metodo es publica.
         */
        return response()->json(
            CategoriaDeNegocioResource::collection(CategoryBusiness::all())->resolve()
        );
    }
}
