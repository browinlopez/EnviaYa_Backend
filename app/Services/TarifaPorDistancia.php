<?php

namespace App\Services;

/**
 * Cuánto cuesta el domicilio según lo lejos que esté la puerta.
 *
 * Hasta ahora era una sola cifra para todo el mundo: lo mismo cruzar la calle
 * que atravesar el barrio. Quien hacía el viaje largo cobraba igual que quien
 * bajaba a la esquina, y el cliente de al lado pagaba el viaje del de lejos.
 *
 * LA ESCALA ES REGULAR, no una tabla de tramos sueltos:
 *
 *     hasta el radio base .................. tarifa base
 *     y de ahí, cada paso .................. + precio del paso
 *
 * Con 1,5 km / 1 km / $1.000 sobre una base de $2.000 sale lo pactado:
 *
 *     0 – 1,5 km  ->  $2.000
 *     1,5 – 2,5   ->  $3.000
 *     2,5 – 3,5   ->  $4.000   … y así
 *
 * Se configura con tres números desde el panel. Una tabla de tramos daría más
 * libertad y exigiría una pantalla propia; esto ya cubre lo que se pidió.
 *
 * LA DISTANCIA ES EN LÍNEA RECTA. No es la que recorre la moto —para eso
 * habría que pagarle a un servicio de rutas en cada pedido, y el resultado
 * cambiaría con el tráfico—. Para cobrar hace falta una cifra estable y que
 * el cliente pueda entender: la recta lo es, y siempre es menor o igual que
 * el recorrido real, así que nunca cobra de más por un rodeo.
 */
class TarifaPorDistancia
{
    /** Radio de la Tierra en kilómetros. */
    private const RADIO_TIERRA_KM = 6371.0;

    /**
     * Kilómetros en línea recta entre dos puntos.
     *
     * Devuelve null si a alguno le faltan coordenadas, que es lo que pasa con
     * los negocios y las direcciones cargados antes de que esto existiera.
     * Quien llama decide qué hacer con esa ignorancia; acá no se inventa un
     * cero, que sería cobrar la tarifa mínima por una entrega a diez cuadras.
     */
    public function kilometros(?float $latA, ?float $lonA, ?float $latB, ?float $lonB): ?float
    {
        if ($latA === null || $lonA === null || $latB === null || $lonB === null) {
            return null;
        }

        /*
         * LA ISLA NULA.
         *
         * `(float) null` es 0.0, y el punto (0, 0) esta en el golfo de Guinea.
         * Un `null` que se cuele por un casteo descuidado no da un error: da
         * ocho mil kilometros y una tarifa de ocho millones de pesos. Ya paso
         * al probar esto con datos reales.
         *
         * Ninguna tienda de Barranquilla esta en (0, 0), asi que tratarlo como
         * "no se sabe" es correcto y ademas atrapa el descuido.
         */
        if (($latA == 0.0 && $lonA == 0.0) || ($latB == 0.0 && $lonB == 0.0)) {
            return null;
        }

        $dLat = deg2rad($latB - $latA);
        $dLon = deg2rad($lonB - $lonA);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLon / 2) ** 2;

        return self::RADIO_TIERRA_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * La tarifa para una distancia dada.
     *
     * Con `$km` en null —porque falta una coordenada— devuelve la tarifa base.
     * Es la decisión conservadora: cobrar el mínimo cuando no se sabe, en vez
     * de cobrarle a alguien un recargo que no se puede justificar.
     */
    public function paraDistancia(?float $km): float
    {
        $base = (float) Ajustes::valor('operacion.tarifa_domicilio');

        if ($km === null) {
            return $base;
        }

        $radioBase = (float) Ajustes::valor('operacion.radio_base_km');
        $paso      = (float) Ajustes::valor('operacion.paso_km');
        $precioPaso = (float) Ajustes::valor('operacion.paso_precio');

        if ($km <= $radioBase || $paso <= 0) {
            return $base;
        }

        /*
         * `ceil` y no `round`: a 1,6 km ya se salió del radio base, así que
         * paga el primer escalón entero. Redondear dejaría un tramo de medio
         * paso cobrado como si no se hubiera salido.
         */
        $escalones = (int) ceil(($km - $radioBase) / $paso);

        return $base + ($escalones * $precioPaso);
    }

    /**
     * La tarifa entre una tienda y una dirección, de una vez.
     *
     * @return array{tarifa: float, km: ?float}  La cifra y la distancia con la
     *         que se calculó, para poder explicarla en pantalla y guardarla.
     */
    public function entre(
        ?float $latNegocio,
        ?float $lonNegocio,
        ?float $latEntrega,
        ?float $lonEntrega,
    ): array {
        $km = $this->kilometros($latNegocio, $lonNegocio, $latEntrega, $lonEntrega);

        return [
            'tarifa' => $this->paraDistancia($km),
            'km'     => $km === null ? null : round($km, 3),
        ];
    }

    /**
     * ¿Esta tienda reparte hasta ahí?
     *
     * En 0 no hay límite. Sin coordenadas se responde que sí: es lo que pasaba
     * antes de que esto existiera, y bloquear por falta de un dato que la
     * tienda todavía no ha cargado sería quitarle ventas por algo que no
     * decidió.
     */
    public function reparteHasta(?float $km): bool
    {
        $maximo = (float) Ajustes::valor('operacion.radio_maximo_km');

        if ($maximo <= 0 || $km === null) {
            return true;
        }

        return $km <= $maximo;
    }
}
