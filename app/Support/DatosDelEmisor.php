<?php

declare(strict_types=1);

namespace App\Support;

/**
 * QUIÉN DICE QUE FIRMA LOS DOCUMENTOS QUE EMITE LA PLATAFORMA.
 *
 * El acuerdo del domiciliario y el comprobante de entrega llevan los datos de
 * la empresa que los emite, y salen de `config/services.php` —o sea, del
 * entorno del servidor—. Hoy producción tiene tres de ellos VACÍOS:
 * `CONTRATO_NIT`, `CONTRATO_REPRESENTANTE` y `CONTRATO_REPRESENTANTE_CC`.
 *
 * El problema no es que el PDF se rompa: no se rompe. La plantilla está bien
 * escrita y envuelve cada dato en un `@if`, así que la cláusula que nombra el
 * NIT simplemente **no se imprime**, y donde iba el representante queda una
 * raya para firmar a mano. El documento sale con pinta de estar completo.
 *
 * Y ese es el fallo: un acuerdo laboral sin el NIT ni el representante legal
 * de quien lo firma es un acuerdo más débil de lo que parece, y nadie se
 * enteraba —ni al generarlo, ni al desplegar, ni mirando el PDF—. Es un hueco
 * que solo se ve cuando ya hay cien acuerdos firmados así.
 *
 * ESTO NO LOS INVENTA NI BLOQUEA LA EMISIÓN. Hacer fallar la generación
 * dejaría a la tienda sin poder dar de alta a un repartidor por una variable
 * de entorno, que es cambiar un hueco en un papel por una puerta cerrada. Lo
 * único que hace es DECIR QUÉ FALTA, en los dos momentos en que sirve de algo:
 * al emitir un documento —queda en el registro— y a petición, con
 * `php artisan configuracion:comprobar`, que es lo que se mira antes de
 * prometerle a alguien que los contratos salen bien.
 */
class DatosDelEmisor
{
    /**
     * Lo que, si falta, deja un hueco en un documento ya firmado.
     *
     * `CONTRATO_EMPRESA`, `CONTRATO_PLATAFORMA` y `CONTRATO_CIUDAD` no están
     * acá: los tres tienen un valor por defecto razonable en
     * `config/services.php`, así que nunca llegan vacíos al papel.
     *
     * @var array<string, array{config: string, es: string}>
     */
    public const OBLIGATORIOS = [
        'CONTRATO_NIT' => [
            'config' => 'services.contrato.nit',
            'es' => 'NIT de la empresa que firma',
        ],
        'CONTRATO_REPRESENTANTE' => [
            'config' => 'services.contrato.representante',
            'es' => 'nombre del representante legal',
        ],
        'CONTRATO_REPRESENTANTE_CC' => [
            'config' => 'services.contrato.representante_cc',
            'es' => 'cédula del representante legal',
        ],
    ];

    /**
     * Las variables de entorno que hacen falta, por su nombre.
     *
     * Devuelve los nombres y no un booleano porque quien pregunta —el registro
     * y el comando— tiene que poder decir CUÁL falta. «La configuración está
     * incompleta» no se puede arreglar; «falta CONTRATO_NIT» sí.
     *
     * @return list<string>
     */
    public static function faltantes(): array
    {
        $faltan = [];

        foreach (self::OBLIGATORIOS as $variable => $dato) {
            if (trim((string) config($dato['config'])) === '') {
                $faltan[] = $variable;
            }
        }

        return $faltan;
    }

    public static function completos(): bool
    {
        return self::faltantes() === [];
    }

    /** Para el registro: «CONTRATO_NIT (NIT de la empresa que firma)». */
    public static function descripcion(string $variable): string
    {
        return isset(self::OBLIGATORIOS[$variable])
            ? $variable.' ('.self::OBLIGATORIOS[$variable]['es'].')'
            : $variable;
    }
}
