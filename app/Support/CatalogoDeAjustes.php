<?php

namespace App\Support;

/**
 * QUÉ SE PUEDE AJUSTAR DESDE EL PANEL
 *
 * Fuente única: de acá salen el formulario de la pantalla, las reglas de
 * validación, el tipo al que se convierte cada valor y el valor por defecto. Con
 * el catálogo en un solo sitio no puede pasar que la pantalla ofrezca un campo
 * que el servidor rechaza, ni que un ajuste se guarde como texto y se lea como
 * número.
 *
 * El VALOR POR DEFECTO sale de la configuración, o sea del `.env`. Así una
 * instalación nueva funciona sin sembrar nada y el `.env` sigue siendo la línea
 * base; la tabla solo guarda lo que alguien cambió a propósito.
 *
 * AGREGAR UN AJUSTE es añadir una entrada acá. No hace falta migrar la tabla ni
 * tocar la pantalla.
 */
class CatalogoDeAjustes
{
    /** Grupos, en el orden en que se pintan. */
    public const GRUPOS = [
        'operacion' => [
            'titulo'   => 'Reglas de la operación',
            'ayuda'    => 'Gobiernan cómo se reparte el dinero y cuándo el panel considera que algo va mal. Se aplican de inmediato a lo nuevo; lo ya entregado guardó sus cifras y no se reescribe.',
        ],
        'app' => [
            'titulo'   => 'Control de la aplicación móvil',
            'ayuda'    => 'Permite parar la app y exigir una versión mínima sin publicar nada en las tiendas. Lo que se ponga acá lo lee la app al abrir, y el servidor además lo aplica.',
        ],
    ];

    /**
     * @return array<string, array{
     *     grupo: string, etiqueta: string, tipo: string, ayuda: string,
     *     defecto: mixed, reglas: string, sufijo?: string, min?: float, max?: float
     * }>
     */
    public static function todos(): array
    {
        return [
            /* ------------------------- OPERACIÓN ------------------------- */

            'operacion.reparto_domiciliario' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Porción del domicilio para el domiciliario',
                'tipo'     => 'porcentaje',
                'ayuda'    => 'De cada tarifa de domicilio, cuánto le queda a quien entrega. El resto es de la plataforma. Se calcula al crear el pedido y se guarda ahí, así que cambiarlo no altera lo ya entregado ni las liquidaciones cerradas.',
                'defecto'  => (float) config('services.domiciliary_share', 0.25),
                'reglas'   => 'required|numeric|min:0|max:1',
            ],

            'operacion.tarifa_domicilio' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Tarifa de domicilio',
                'tipo'     => 'entero',
                'sufijo'   => 'COP',
                'ayuda'    => 'Lo que paga el cliente por la entrega. La app la consulta al abrir, así que cambiarla acá se ve en el teléfono sin publicar una versión nueva. Se congela en cada pedido al crearlo: subirla no reescribe lo ya entregado. Los pedidos que el cliente recoge en tienda no la pagan.',
                'defecto'  => (int) config('services.delivery_fee', 2000),
                'reglas'   => 'required|integer|min:0|max:100000',
            ],

            /*
             * LA TARIFA POR DISTANCIA.
             *
             * Hasta ahora el domicilio costaba lo mismo a una cuadra que al
             * otro lado del barrio, y quien hacia el viaje largo cobraba igual
             * que quien cruzaba la calle.
             *
             * La escala es regular a proposito: un radio base con la tarifa de
             * arriba, y de ahi en adelante un escalon fijo. Con
             * 1,5 km / 1 km / $1.000 sale lo pactado —hasta 1,5 km $2.000,
             * hasta 2,5 km $3.000, hasta 3,5 km $4.000— y se puede mover sin
             * tocar codigo. Una tabla de tramos sueltos daria mas libertad y
             * exigiria pantalla propia; esto se configura con tres numeros.
             */
            'operacion.radio_base_km' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Radio de la tarifa base',
                'tipo'     => 'decimal',
                'sufijo'   => 'km',
                'ayuda'    => 'Hasta esta distancia en linea recta entre la tienda y la direccion, el domicilio cuesta la tarifa base. Mas alla empieza a subir por escalones.',
                'defecto'  => 1.5,
                'reglas'   => 'required|numeric|min:0.1|max:50',
            ],

            'operacion.paso_km' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Cada cuanto sube',
                'tipo'     => 'decimal',
                'sufijo'   => 'km',
                'ayuda'    => 'Pasado el radio base, cada cuantos kilometros sube un escalon la tarifa.',
                'defecto'  => 1.0,
                'reglas'   => 'required|numeric|min:0.1|max:50',
            ],

            'operacion.paso_precio' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Cuanto sube cada escalon',
                'tipo'     => 'entero',
                'sufijo'   => 'COP',
                'ayuda'    => 'Lo que se suma a la tarifa por cada escalon de distancia. Se congela en el pedido al crearlo, como el resto de lo que decide dinero.',
                'defecto'  => 1000,
                'reglas'   => 'required|integer|min:0|max:100000',
            ],

            'operacion.radio_maximo_km' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Hasta donde se entrega',
                'tipo'     => 'decimal',
                'sufijo'   => 'km',
                'ayuda'    => 'Distancia maxima a la que una tienda reparte. Mas alla no aparece en la lista de quien busca, y su catalogo se puede mirar pero no pedir. En 0 no hay limite.',
                'defecto'  => 6.0,
                'reglas'   => 'required|numeric|min:0|max:100',
            ],

            'operacion.comision_plataforma' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Comisión de la plataforma sobre la venta',
                'tipo'     => 'porcentaje',
                'ayuda'    => 'De lo que vende un negocio, cuánto retiene la plataforma al liquidarle. Se calcula sobre el subtotal de productos menos los descuentos —el domicilio va aparte, con su propio reparto— y se congela en cada pedido al crearlo: subirla no rehace liquidaciones ya emitidas ni le cambia a nadie lo que se le pagó el mes pasado.',
                'defecto'  => (float) config('services.platform_commission', 0.03),
                'reglas'   => 'required|numeric|min:0|max:1',
            ],

            'operacion.tiempo_entrega_min' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Plazo de entrega prometido',
                'tipo'     => 'entero',
                'sufijo'   => 'minutos',
                'ayuda'    => 'El estándar que la operación se compromete a cumplir, contado desde que la tienda despacha. La app lo muestra al cliente y el reloj del seguimiento corre contra él. Se congela en cada pedido al despacharlo, así que subirlo no convierte en "a tiempo" entregas pasadas que llegaron tarde: el rendimiento de cada domiciliario se mide contra el plazo que regía ese día.',
                'defecto'  => 20,
                'reglas'   => 'required|integer|min:5|max:240',
            ],

            'operacion.entregas_simultaneas' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Entregas a la vez por domiciliario',
                'tipo'     => 'entero',
                'ayuda'    => 'Tope de pedidos activos que puede llevar una misma persona. Subirlo aumenta la capacidad y empeora los tiempos; bajarlo hace lo contrario.',
                'defecto'  => (int) config('services.max_active_deliveries', 3),
                'reglas'   => 'required|integer|min:1|max:20',
            ],

            /*
             * El tope existe para proteger al CLIENTE, no al servidor.
             *
             * Una promoción llega como notificación al teléfono de gente que se
             * afilió a la tienda por su voluntad. Tres avisos al día de la misma
             * tienda y esa gente apaga las notificaciones de la app entera —no
             * las de esa tienda, las de todas—, y entonces tampoco se enteran de
             * que su pedido va en camino. El daño no se queda en quien abusa.
             *
             * Se pone acá y no como constante para poder subirlo un diciembre
             * sin tocar código.
             */
            'operacion.promociones_por_dia' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Promociones que puede enviar una tienda al día',
                'tipo'     => 'entero',
                'ayuda'    => 'Cuántas veces al día puede una tienda avisar a sus clientes afiliados. Subirlo mucho hace que la gente silencie los avisos de la app, y entonces se pierden también los de sus pedidos.',
                'defecto'  => 2,
                'reglas'   => 'required|integer|min:1|max:10',
            ],

            'operacion.horas_estancado' => [
                'grupo'    => 'operacion',
                'etiqueta' => 'Horas para considerar un pedido atascado',
                'tipo'     => 'entero',
                'sufijo'   => 'horas',
                'ayuda'    => 'A partir de acá el pedido aparece en "requieren atención" y entra en el resumen diario por correo. Bajarlo llena la lista de casos que todavía iban bien; subirlo esconde los que ya se enfriaron.',
                'defecto'  => 24,
                'reglas'   => 'required|integer|min:1|max:240',
            ],

            /* --------------------- CONTROL DE LA APP --------------------- */

            'app.mantenimiento' => [
                'grupo'    => 'app',
                'etiqueta' => 'App en mantenimiento',
                'tipo'     => 'booleano',
                'ayuda'    => 'Encendido, la app deja de operar y el servidor responde 503 a las peticiones de compradores y domiciliarios. El panel sigue funcionando —si no, no habría forma de apagarlo— y los avisos de pago de la pasarela también, para no perder cobros en curso.',
                'defecto'  => false,
                'reglas'   => 'required|boolean',
            ],

            'app.mantenimiento_mensaje' => [
                'grupo'    => 'app',
                'etiqueta' => 'Mensaje de mantenimiento',
                'tipo'     => 'texto_largo',
                'ayuda'    => 'Lo que lee la persona en la app mientras está parado. Un mensaje con una hora estimada evita la mitad de las llamadas.',
                'defecto'  => 'Estamos en mantenimiento y volvemos en un rato. Gracias por la paciencia.',
                'reglas'   => 'nullable|string|max:300',
            ],

            'app.version_minima_android' => [
                'grupo'    => 'app',
                'etiqueta' => 'Versión mínima en Android',
                'tipo'     => 'version',
                'ayuda'    => 'Quien tenga una anterior verá que debe actualizar antes de seguir. Se usa cuando una versión vieja ya no habla bien con el servidor. Vacío = no se exige ninguna.',
                'defecto'  => '',
                'reglas'   => 'nullable|string|max:20|regex:/^\d+(\.\d+){0,2}$/',
            ],

            'app.version_minima_ios' => [
                'grupo'    => 'app',
                'etiqueta' => 'Versión mínima en iOS',
                'tipo'     => 'version',
                'ayuda'    => 'Igual que la de Android, aparte porque las tiendas aprueban en tiempos distintos y casi nunca van a la par.',
                'defecto'  => '',
                'reglas'   => 'nullable|string|max:20|regex:/^\d+(\.\d+){0,2}$/',
            ],

            'app.mensaje_actualizacion' => [
                'grupo'    => 'app',
                'etiqueta' => 'Mensaje al exigir actualización',
                'tipo'     => 'texto_largo',
                'ayuda'    => 'Lo que se le dice a quien tiene una versión por debajo de la mínima.',
                'defecto'  => 'Hay una versión nueva con mejoras importantes. Actualiza para seguir pidiendo.',
                'reglas'   => 'nullable|string|max:300',
            ],
        ];
    }

    public static function existe(string $clave): bool
    {
        return array_key_exists($clave, self::todos());
    }

    public static function definicion(string $clave): ?array
    {
        return self::todos()[$clave] ?? null;
    }

    /** Las reglas de validación de todo el catálogo, para un `validate()`. */
    public static function reglas(): array
    {
        $reglas = [];

        foreach (self::todos() as $clave => $def) {
            // Los ajustes llegan como un objeto plano: {"operacion.horas_estancado": 24}
            $reglas[$clave] = 'sometimes|' . $def['reglas'];
        }

        return $reglas;
    }

    /**
     * Convierte el texto guardado al tipo que declara el catálogo.
     *
     * Todo se guarda como texto en una sola columna, así que sin esto
     * `app.mantenimiento` volvería como la cadena "0" —que en PHP es falsa, pero
     * en JSON es `"0"` y en JavaScript es VERDADERA—. Un modo mantenimiento que
     * se enciende solo por el tipo de dato es exactamente el fallo que nadie
     * encuentra leyendo el código.
     */
    public static function convertir(string $clave, ?string $crudo): mixed
    {
        $def = self::definicion($clave);

        if (!$def || $crudo === null) {
            return $def['defecto'] ?? null;
        }

        return match ($def['tipo']) {
            'porcentaje' => (float) $crudo,
            'decimal'    => (float) $crudo,
            'entero'     => (int) $crudo,
            'booleano'   => filter_var($crudo, FILTER_VALIDATE_BOOLEAN),
            default      => $crudo,
        };
    }

    /** El texto con el que se guarda un valor ya validado. */
    public static function aTexto(string $clave, mixed $valor): string
    {
        $def = self::definicion($clave);

        return match ($def['tipo'] ?? 'texto') {
            'booleano' => filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default    => (string) $valor,
        };
    }
}
