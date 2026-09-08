<?php

namespace App\Services;

use App\Models\Business;
use App\Models\DeviceToken;
use App\Models\Promotion;
use App\Services\Push\TransportePush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LA PROMOCIÓN ESCRITA DEL TENDERO, Y A QUIÉN LE LLEGA
 *
 * El tendero escribe una frase —«Compra 2 atunes y el tercero va gratis»—,
 * marca a qué productos se refiere y hasta cuándo dura, y les llega a los
 * clientes afiliados a su tienda.
 *
 * NACIÓ COMO UN AVISO Y AHORA DESCUENTA. La primera versión solo mandaba el
 * texto: el tendero cumplía la promesa en el mostrador. Los tenderos pidieron
 * que se aplicara de verdad, así que cada promoción lleva una REGLA —un
 * porcentaje o un «lleva N paga M»— que `DescuentoPorPromocion` convierte en
 * pesos al armar el pedido.
 *
 * LO QUE NO CAMBIÓ es de quién es esta pantalla. La persona que la usa atiende
 * un local y no maneja formularios, así que la regla NO se le pide aparte: sale
 * de la frase que elige. «2x1» ya dice lleva 2 paga 1, y «20% de descuento» ya
 * dice el porcentaje. Lo único que ajusta a mano es ese número, y con botones.
 *
 * DOS CANALES, Y LOS DOS HACEN FALTA:
 *
 *   · El aviso guardado (`Avisos::para`) es el que SOBREVIVE. Queda en la
 *     campana del cliente y sigue ahí mañana.
 *   · El push es el que INTERRUMPE. Es lo que hace que alguien se entere hoy,
 *     que es de lo que va una promoción.
 *
 * Si solo hubiera push, quien tuviera el teléfono en silencio no se entera
 * nunca. Si solo hubiera aviso guardado, se entera el día que abra la app, que
 * puede ser después de que la promoción acabe.
 *
 * SE ENVÍA EN LA MISMA PETICIÓN, sin cola. La cola sería lo correcto para una
 * campaña de la plataforma entera —y para eso está `EnviarLotePush`—, pero acá
 * el público es la clientela de UNA tienda de barrio: decenas, no miles. Y
 * encolar tiene un riesgo que no compensa a esta escala: si el trabajador de la
 * cola no está corriendo, la promoción no sale y NADIE se entera de que no
 * salió, ni el tendero, que la ve en su lista como enviada. Un envío directo
 * falla a la vista.
 *
 * El tope de `MAXIMO_DESTINATARIOS` es el límite de esa decisión: pasado eso,
 * la petición tardaría lo suficiente como para agotar el tiempo de espera y hay
 * que mover esto a la cola. Se comprueba y se dice, en vez de descubrirlo el
 * día que una tienda crezca.
 */
class PromocionesDeLaTienda
{
    /**
     * Cuántos caracteres caben en una promoción.
     *
     * No es un capricho: Android recorta el cuerpo de una notificación
     * alrededor de aquí cuando está plegada, y una promoción que se lee a
     * medias —«Compra 2 atunes y el terce…»— es peor que ninguna. El contador
     * en pantalla usa este mismo número.
     */
    public const MAXIMO_CARACTERES = 180;

    /** A partir de acá esto tiene que ir en cola. Ver la cabecera. */
    public const MAXIMO_DESTINATARIOS = 500;

    /**
     * HASTA CUÁNDO DURA, SIN CALENDARIO.
     *
     * Un selector de fecha es de lo más difícil que hay para quien no maneja el
     * teléfono: hay que entender el mes, moverse entre pantallas y no
     * equivocarse de año. Y una promoción de tienda de barrio nunca dura
     * «hasta el 23 de octubre»: dura hoy, el fin de semana, o esta semana.
     *
     * Son cuatro botones y uno de «sin fecha». El que quiera un día exacto
     * puede escribirlo en el texto, que es lo que ya hacía.
     *
     * LA FECHA LA CALCULA EL SERVIDOR, no el teléfono. El reloj de un teléfono
     * puede ir mal o estar en otra zona horaria, y entonces «solo hoy» vencería
     * a media tarde o duraría hasta pasado mañana.
     */
    public const PLAZOS = ['hoy', 'manana', 'fin_de_semana', 'semana', 'sin_fecha'];

    /** El instante en que deja de valer, en hora colombiana. */
    public function fechaLimite(?string $plazo): ?\Illuminate\Support\Carbon
    {
        $hoy = now('America/Bogota');

        return match ($plazo) {
            'hoy'   => $hoy->copy()->endOfDay(),
            'manana' => $hoy->copy()->addDay()->endOfDay(),
            // El domingo de ESTA semana. Si ya es domingo, hoy mismo: prometer
            // el domingo que viene sería una semana entera de más.
            'fin_de_semana' => $hoy->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->endOfDay(),
            'semana' => $hoy->copy()->addDays(7)->endOfDay(),
            default  => null,
        };
    }

    public function __construct(private TransportePush $push)
    {
    }

    /** Los usuarios afiliados a esta tienda. Son los que reciben. */
    public function destinatarios(int $businessId): array
    {
        return DB::table('business_user_affiliations as a')
            ->join('user as u', 'u.user_id', '=', 'a.user_id')
            ->where('a.busines_id', $businessId)
            // Una cuenta desactivada no debe recibir nada: si volviera, le
            // llegaría de golpe la promoción de una semana que ya pasó.
            ->where('u.state', 1)
            ->pluck('u.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Cuántas ha mandado hoy esta tienda, para el tope diario. */
    public function enviadasHoy(int $businessId): int
    {
        return Promotion::where('busines_id', $businessId)
            ->whereNotNull('sent_at')
            ->whereDate('sent_at', now()->toDateString())
            ->count();
    }

    /** Lo que le queda por enviar hoy. Cero significa que ya no puede. */
    public function leQuedanHoy(int $businessId): int
    {
        $tope = (int) Ajustes::valor('operacion.promociones_por_dia');

        return max(0, $tope - $this->enviadasHoy($businessId));
    }

    /**
     * Crea la promoción y la reparte.
     *
     * @param  list<int>  $productos  Identificadores del catálogo. Puede ir vacío.
     */
    public function publicar(
        Business $negocio,
        string $texto,
        array $productos,
        int $creadaPor,
        ?string $plazo = null,
        array $regla = [],
    ): Promotion {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        $hasta = $this->fechaLimite($plazo);

        /*
         * La fila y sus productos van en una transacción, y el REPARTO va
         * fuera.
         *
         * Si el push de Firebase tarda o falla, la promoción ya está escrita y
         * guardada: lo peor que pasa es que llegue a menos gente. Al revés
         * —repartir dentro de la transacción— un fallo al final desharía la
         * promoción DESPUÉS de que varios clientes ya la hubieran recibido en
         * el teléfono, y quedaría un aviso de algo que no existe.
         */
        $promocion = DB::transaction(function () use ($negocio, $texto, $productos, $creadaPor, $hasta, $regla) {
            $promocion = Promotion::create([
                'busines_id'  => $negocio->busines_id,
                'description' => $texto,
                'start_date'  => now(),
                'end_date'    => $hasta,
                'state'       => Promotion::BORRADOR,
                'created_by'  => $creadaPor,
                ...$regla,
            ]);

            if ($productos !== []) {
                $promocion->products()->sync(array_values(array_unique($productos)));
            }

            return $promocion;
        });

        $destinatarios = $this->destinatarios($negocio->busines_id);

        $this->avisar($promocion, $negocio, $texto, $destinatarios);

        $promocion->forceFill([
            'state'            => Promotion::ENVIADA,
            'sent_at'          => now(),
            'recipients_count' => count($destinatarios),
        ])->save();

        return $promocion->load('products');
    }

    /**
     * CORREGIR UNA PROMOCIÓN QUE YA SALIÓ.
     *
     * Lo que se puede arreglar y lo que no, dicho sin rodeos:
     *
     *   · SÍ se arregla el aviso en la campana de cada cliente. Esas filas son
     *     nuestras y se reescriben. Quien entre a mirar verá el texto bueno.
     *   · NO se arregla el zumbido que ya sonó. La notificación del sistema
     *     salió del teléfono hace rato y no hay forma de alcanzarla.
     *
     * Por eso corregir NO vuelve a mandar push: sonar dos veces por la misma
     * promoción es peor que el error de dedo que se venía a arreglar.
     *
     * @param  list<int>  $productos
     */
    public function corregir(
        Promotion $promocion,
        string $texto,
        array $productos,
        ?string $plazo,
        array $regla = [],
    ): Promotion {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));

        DB::transaction(function () use ($promocion, $texto, $productos, $plazo, $regla) {
            $promocion->description = $texto;
            $promocion->end_date = $this->fechaLimite($plazo);
            $promocion->fill($regla);
            $promocion->save();

            $promocion->products()->sync(array_values(array_unique($productos)));
        });

        $this->reescribirAvisos($promocion, $texto);

        return $promocion->fresh()->load('products');
    }

    /**
     * RETIRARLA.
     *
     * Quita el aviso de la campana de todos y la marca como retirada. La fila
     * NO se borra: el tope diario cuenta lo que salió hoy, y borrar dejaría el
     * cupo libre para mandar otra —mandar, borrar, mandar, borrar sería un
     * agujero por el que caben cinco notificaciones seguidas—.
     */
    public function retirar(Promotion $promocion): void
    {
        DB::table('notifications')
            ->where('tipo', 'promocion')
            ->where('datos->promotion_id', $promocion->promotion_id)
            ->delete();

        $promocion->state = Promotion::RETIRADA;
        $promocion->save();
    }

    /** El texto nuevo en la campana de cada uno que lo recibió. */
    private function reescribirAvisos(Promotion $promocion, string $texto): void
    {
        DB::table('notifications')
            ->where('tipo', 'promocion')
            ->where('datos->promotion_id', $promocion->promotion_id)
            ->update([
                'message' => $texto,
                /*
                 * Vuelve a marcarse como NO LEÍDO.
                 *
                 * Quien ya lo había leído tiene en la cabeza la versión
                 * equivocada —el precio mal, el día que no era—, así que
                 * conviene que le vuelva a aparecer. Es el único aviso que
                 * puede volver atrás, y por eso se hace solo al corregir.
                 */
                'read' => false,
            ]);
    }

    /**
     * El aviso guardado a cada uno, y el push a sus teléfonos.
     *
     * @param  list<int>  $destinatarios
     */
    private function avisar(
        Promotion $promocion,
        Business $negocio,
        string $texto,
        array $destinatarios,
    ): void {
        if ($destinatarios === []) {
            return;
        }

        $datos = [
            'promotion_id' => $promocion->promotion_id,
            'business_id'  => $negocio->busines_id,
            // El nombre viaja EN el aviso y no se resuelve al pintarlo: si la
            // tienda cambia de nombre, el aviso viejo tiene que seguir diciendo
            // lo que decía cuando llegó.
            'business_name' => $negocio->name,
            /*
             * El plazo va en `datos` y NO pegado al texto.
             *
             * Pegarlo produciría «Compra 2 y el tercero va gratis. (Solo hoy)»
             * —la frase del tendero alterada— y además envejecería mal: mañana
             * ese aviso seguiría diciendo «solo hoy». Acá la campana lo pinta
             * como una línea aparte y puede dejar de enseñarla al vencer.
             */
            'hasta'       => $promocion->end_date?->toIso8601String(),
            'hasta_texto' => $promocion->hastaEnPalabras(),
        ];

        foreach ($destinatarios as $userId) {
            Avisos::para($userId, 'promocion', $texto, $datos);
        }

        $tokens = DeviceToken::vivos()
            ->whereIn('user_id', $destinatarios)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return;
        }

        try {
            /*
             * El título es el NOMBRE DE LA TIENDA, no «Promoción».
             *
             * En la barra de notificaciones se compite con veinte apps. «Tienda
             * el progreso» lo reconoce quien compra ahí; «Nueva promoción» lo
             * escribe cualquiera y se descarta sin leer.
             */
            $this->push->enviar(
                $tokens,
                $negocio->name,
                $texto,
                [
                    'tipo'         => 'promocion',
                    'promotion_id' => (string) $promocion->promotion_id,
                    'business_id'  => (string) $negocio->busines_id,
                ],
            );
        } catch (\Throwable $e) {
            // El aviso ya está guardado en la campana de cada uno, así que la
            // promoción no se pierde: se pierde la inmediatez.
            Log::warning('No se pudo enviar el push de la promoción', [
                'promotion_id' => $promocion->promotion_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
