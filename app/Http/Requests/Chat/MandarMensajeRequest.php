<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un mensaje del chat, que puede ser texto o una tarjeta de pedido.
 */
class MandarMensajeRequest extends FormRequest
{
    /** Las tarjetas que existen de verdad. */
    private const TARJETAS = ['preorder', 'preorder_status', 'cupon_regalo'];

    /** Lo que cabe en un mensaje escrito. */
    private const TOPE_TEXTO = 1000;

    /** Lo que cabe en una tarjeta: la columna es TEXT y le caben 64 KB. */
    private const TOPE_TARJETA = 30000;

    /*
     * La ruta ya exige sesion. Y escribir EN NOMBRE DE OTRO lo impide el
     * controlador comparando `user_id` con la sesion, que es donde tiene que
     * estar: aqui solo se mira la forma de lo que llega.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:user,user_id',
            'recipient_id' => 'required|exists:user,user_id',
            'content' => 'required|string|max:'.$this->tope(),
        ];
    }

    /**
     * Mil caracteres para un mensaje, treinta mil para una tarjeta.
     *
     * UN MENSAJE ESCRITO NO PASA DE MIL LETRAS; una TARJETA —el pedido
     * armado, que viaja como JSON con nombres, precios y fotos de cada
     * producto— pasaba de mil con cuatro productos y el envio se rechazaba.
     *
     * Y SOLO LAS TARJETAS QUE EXISTEN: con cualquier `type` bastaba envolver
     * un texto en JSON para saltarse el tope de mil. Por eso se comprueba
     * contra la lista y no contra «parece JSON».
     *
     * Esto vive en el FormRequest y no en el controlador porque es
     * exactamente su trabajo: decidir que entrada se acepta. El controlador
     * se queda con lo suyo, que es a quien se le manda.
     */
    private function tope(): int
    {
        $contenido = $this->input('content');

        if (! is_string($contenido)) {
            return self::TOPE_TEXTO;
        }

        $tarjeta = json_decode($contenido, true);

        return is_array($tarjeta) && in_array($tarjeta['type'] ?? null, self::TARJETAS, true)
            ? self::TOPE_TARJETA
            : self::TOPE_TEXTO;
    }
}
