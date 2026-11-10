<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El Excel con el que un tendero carga su surtido de una vez.
 */
class SubirCatalogoRequest extends FormRequest
{
    /*
     * El permiso lo resuelve el middleware de la ruta, que es donde vive en
     * este backend. Repetirlo aqui seria una segunda fuente de verdad sobre
     * quien puede que, y la que se olvide de actualizar sera la que mande.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * `extensions` Y NO `mimes`, Y NO ES UN DESCUIDO.
             *
             * `mimes` no mira la extensión: adivina el tipo por el contenido y
             * comprueba que ESE tipo corresponda a la extensión. El .xls que
             * exportan los puntos de venta de las tiendas es BIFF2, de 1987, y
             * nadie lo tiene en su tabla de tipos: se adivina como
             * `application/octet-stream`, que no corresponde a `xls`, y la
             * regla lo rechazaba con «debe ser un archivo de tipo xlsx, xls,
             * csv, txt» sobre un archivo .xls que Excel abre sin protestar.
             *
             * Imposible de entender desde el otro lado, e imposible de ver en
             * una prueba con `UploadedFile::fake()`, porque el archivo falso
             * declara el tipo que uno le dice.
             *
             * `extensions` comprueba la extensión, que es lo que aquí se
             * quiere. Lo que el archivo sea por dentro lo decide el lector, y
             * si no sabe leerlo lo dice con un motivo que se entiende.
             *
             * 10 MB da para varios miles de filas y corta el archivo que
             * alguien sube por error.
             */
            'archivo' => 'required|file|extensions:xlsx,xls,csv,txt|max:10240',
            /*
             * Para lo que el archivo no clasifique. Los que exporta un punto
             * de venta no traen columna de categoría, y sin esto la carga
             * entera se rechazaba fila por fila.
             */
            'categoria_id' => 'nullable|integer|exists:category,category_id',
        ];
    }
}
