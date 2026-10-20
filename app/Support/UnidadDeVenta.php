<?php

namespace App\Support;

/**
 * CÓMO SE VENDE UN PRODUCTO EN UNA TIENDA.
 *
 * Por unidad —una lata, un paquete— o al peso. Y al peso en las tres medidas
 * con las que se habla de verdad en el mostrador colombiano: el kilo, la
 * libra («dame una libra de queso» es más común que «500 gramos») y el gramo,
 * para lo que se vende en poca cantidad.
 *
 * Es del PAR tienda-producto y no del producto: la misma referencia va
 * empacada en una tienda y al peso en la de al lado. Por eso vive en
 * `products_business` y no en `products` ni en `ProductTypeSchema`, que
 * describe el catálogo compartido.
 *
 * Es una lista en PHP y no un ENUM de MySQL porque añadir una medida tiene
 * que costar una línea acá, no un ALTER sobre la tabla de precios de todas
 * las tiendas.
 */
class UnidadDeVenta
{
    public const UNIDAD = 'unidad';
    public const KILO   = 'kg';
    public const LIBRA  = 'libra';
    public const GRAMO  = 'gramo';

    public const TODAS = [self::UNIDAD, self::KILO, self::LIBRA, self::GRAMO];

    /** Las que se pesan. */
    public const AL_PESO = [self::KILO, self::LIBRA, self::GRAMO];

    public static function esPeso(?string $unidad): bool
    {
        return in_array($unidad, self::AL_PESO, true);
    }

    public static function valida(?string $unidad): bool
    {
        return in_array($unidad, self::TODAS, true);
    }

    /**
     * Cómo se nombra una cantidad en esa unidad: «1,18 kg», «3 unidades».
     *
     * Se arma acá y no en cada pantalla para que el comprador, el tendero y
     * el comprobante digan exactamente lo mismo. Los decimales se recortan
     * cuando no aportan —«2 kg» y no «2,000 kg»— porque un número con ceros
     * de relleno se lee como una precisión que no existe.
     */
    public static function enPalabras(float $cantidad, ?string $unidad): string
    {
        $unidad = $unidad ?: self::UNIDAD;

        if ($unidad === self::UNIDAD) {
            $n = (int) round($cantidad);

            return $n . ' ' . ($n === 1 ? 'unidad' : 'unidades');
        }

        // Los gramos no llevan decimales: medio gramo no se pesa ni se cobra.
        $decimales = $unidad === self::GRAMO ? 0 : 3;
        $texto = rtrim(rtrim(number_format($cantidad, $decimales, ',', '.'), '0'), ',');

        return $texto . ' ' . match ($unidad) {
            self::KILO  => 'kg',
            self::LIBRA => $cantidad == 1 ? 'libra' : 'libras',
            self::GRAMO => 'g',
            default     => '',
        };
    }
}
