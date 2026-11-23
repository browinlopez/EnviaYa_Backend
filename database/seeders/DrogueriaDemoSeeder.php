<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Operacion\CashDeposit;
use App\Models\Promotion;
use App\Support\ProductTypeSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ==========================================================================
 * LO QUE LE FALTABA A LA DROGUERÍA PARA PODERLA MIRAR ENTERA.
 *
 * «Droguería Vida Sana» tenía productos, pedidos, reseñas y domiciliarios,
 * pero cinco pantallas del panel salían vacías: Clientes, Efectivo,
 * Promociones, el fiado y las facturas. Una pantalla vacía no se puede
 * revisar: no se distingue «está bien y no hay datos» de «está rota».
 *
 * Medido tabla por tabla contra «Tienda de prueba», que sí tenía de todo:
 *
 *     business_user_affiliations   tienda 8   droguería 0
 *     store_credits                tienda 4   droguería 0
 *     cash_deposits                tienda 3   droguería 0
 *     promotions                   tienda 2   droguería 0
 *
 * --------------------------------------------------------------------------
 * Y LOS TEXTOS SON DE DROGUERÍA, NO DE TIENDA.
 *
 * El dato de prueba que habla de otro negocio es peor que no tener dato: en
 * la conversación de la droguería alguien preguntaba por «leche
 * deslactosada», y eso hace dudar de si la pantalla está enseñando lo que
 * debe. Las promociones, el fiado y el chat de acá hablan de lo que se vende
 * en un mostrador de droguería.
 *
 * --------------------------------------------------------------------------
 * SE PUEDE CORRER DOS VECES.
 *
 * Todo con `updateOrInsert` o `firstOrCreate` por una llave estable. Un
 * seeder de demostración que duplica en cada corrida obliga a vaciar la base
 * para volver a usarlo, y entonces deja de servir para ir mirando.
 * ========================================================================== */
class DrogueriaDemoSeeder extends Seeder
{
    /** Quien atiende: Yeimy Polo, la dueña. */
    private const DUENA = 20;

    public function run(): void
    {
        $negocio = (int) DB::table('business')
            ->where('type', ProductTypeSchema::TYPE_PHARMACY)
            ->orderBy('busines_id')
            ->value('busines_id');

        if (! $negocio) {
            $this->command?->error('No hay ninguna droguería en esta base.');

            return;
        }

        $nombre = DB::table('business')->where('busines_id', $negocio)->value('name');

        $this->tope($negocio);
        $this->clientes($negocio);
        $this->fiado($negocio);
        $this->efectivo($negocio);
        $this->promociones($negocio);
        $this->conversacion();

        $this->command?->info("Listo. «{$nombre}» (negocio {$negocio}) ya tiene:");
        $this->command?->line('  · '.DB::table('business_user_affiliations')->where('busines_id', $negocio)->count().' clientes afiliados');
        $this->command?->line('  · '.DB::table('store_credits')->where('busines_id', $negocio)->count().' cupos de fiado');
        $this->command?->line('  · '.DB::table('cash_deposits')->where('busines_id', $negocio)->count().' entregas de efectivo');
        $this->command?->line('  · '.DB::table('promotions')->where('busines_id', $negocio)->count().' promociones');
    }

    /* ------------------------------------------------------------------ */

    /**
     * EL TOPE DE FIADO DEL NEGOCIO, Y VA PRIMERO POR UNA RAZÓN.
     *
     * `credit_debt_cap` es lo que decide si el negocio puede fiar: sin él,
     * `CreditoDeTienda::resumenDeLaTienda` devuelve `habilitado = false` y la
     * pantalla saca el aviso «Todavía no tienes crédito habilitado».
     *
     * La primera versión de este seeder repartía cupos sin ponerlo, y el
     * resultado era una pantalla que se contradecía sola: $215.000 repartidos
     * entre cuatro clientes, «Por repartir $0», y encima un cartel diciendo
     * que no hay crédito. Un estado que el código de verdad NO puede producir
     * —no se asigna cupo sin tope— y que por eso habría mandado a buscar un
     * fallo donde no lo había.
     *
     * $400.000 y no el millón y medio de la tienda: en una droguería se fía
     * el remedio de la semana, no el mercado del mes.
     */
    private function tope(int $negocio): void
    {
        DB::table('business')
            ->where('busines_id', $negocio)
            ->whereNull('credit_debt_cap')
            ->update(['credit_debt_cap' => 400000]);
    }

    /**
     * Los vecinos que le compran.
     *
     * Se afilian los MISMOS que ya tienen conversación con la dueña —Jorge y
     * Katherine— más otros cinco. Si el que te escribe no aparece en tu lista
     * de clientes, la pantalla de Clientes se lee como si estuviera rota.
     */
    private function clientes(int $negocio): void
    {
        $compradores = DB::table('user')
            ->where('rol', 1)
            ->whereIn('user_id', [31, 32, 33, 34, 39, 40, 41])
            ->pluck('user_id');

        foreach ($compradores as $uid) {
            DB::table('business_user_affiliations')->updateOrInsert(
                ['busines_id' => $negocio, 'user_id' => (int) $uid],
                [],
            );
        }
    }

    /**
     * A quién se le fía, y hasta cuánto.
     *
     * Cupos bajos a propósito. En una droguería se fía el remedio de la
     * semana, no el mercado del mes: los montos de una tienda de barrio
     * —doscientos, trescientos mil— darían una idea equivocada de para qué
     * sirve esta pantalla.
     */
    private function fiado(int $negocio): void
    {
        $cupos = [
            31 => 60000,   // Laura Mendoza
            33 => 45000,   // Katherine Ariza
            39 => 80000,   // Sofía Herrera
            40 => 30000,   // Alberto Cantillo
        ];

        foreach ($cupos as $uid => $tope) {
            if (! DB::table('user')->where('user_id', $uid)->exists()) {
                continue;
            }

            DB::table('store_credits')->updateOrInsert(
                ['busines_id' => $negocio, 'user_id' => $uid],
                [
                    'credit_limit' => $tope,
                    'created_by' => self::DUENA,
                    'updated_at' => now(),
                    'created_at' => now()->subDays(12),
                ],
            );
        }
    }

    /**
     * Lo que los domiciliarios le entregaron del efectivo que cobraron.
     *
     * Una confirmada, una pendiente y una por transferencia: con las tres
     * confirmadas la pantalla no enseña el caso que importa, que es el dinero
     * que todavía está en la calle.
     *
     * `idempotency_key` fija por fila y no al azar: es lo que hace que correr
     * el seeder dos veces no cuente el mismo depósito dos veces. La clave de
     * intento existe justo por eso —un recibo de efectivo que se repetía bajó
     * un saldo dos veces por un billete de $5.000— y un dato de prueba que la
     * esquive enseñaría un saldo que el código de verdad no produce.
     */
    private function efectivo(int $negocio): void
    {
        $domiciliarios = DB::table('business_domiciliary')
            ->where('busines_id', $negocio)
            ->orderBy('domiciliary_id')
            ->pluck('domiciliary_id')
            ->all();

        $entregas = [
            ['canal' => CashDeposit::MOSTRADOR, 'monto' => 38400, 'estado' => 'confirmada',
                'ref' => 'Entregado en el mostrador', 'dias' => 2],
            ['canal' => CashDeposit::MOSTRADOR, 'monto' => 21900, 'estado' => 'pendiente',
                'ref' => 'Dice que lo deja mañana', 'dias' => 0],
            /* `consignacion` y no «transferencia»: los dos únicos canales que
               el código produce son `CashDeposit::MOSTRADOR` y
               `CashDeposit::CONSIGNACION`. Un valor inventado no revienta
               nada --la fila entra-- pero la pantalla de Consignaciones seguía
               diciendo «tus domiciliarios no han consignado» con el depósito
               ahí puesto, que es un dato de prueba mintiendo. */
            ['canal' => CashDeposit::CONSIGNACION, 'monto' => 54750, 'estado' => 'confirmada',
                'ref' => 'Consignación Bancolombia 4455', 'dias' => 4],
        ];

        foreach ($entregas as $i => $e) {
            if (! isset($domiciliarios[$i])) {
                break;
            }

            /* Derivada del negocio y la posición, NO al azar: tiene que salir
               igual en cada corrida o el seeder deja de ser idempotente. */
            $llave = self::comoUuid("demo-drogueria-{$negocio}-{$i}");

            DB::table('cash_deposits')->updateOrInsert(
                ['busines_id' => $negocio, 'domiciliary_id' => (int) $domiciliarios[$i]],
                [
                    'canal' => $e['canal'],
                    'amount' => $e['monto'],
                    'reference' => $e['ref'],
                    'state' => $e['estado'],
                    'idempotency_key' => $llave,
                    'deposited_at' => now()->subDays($e['dias']),
                    'confirmed_by' => $e['estado'] === 'confirmada' ? self::DUENA : null,
                    'confirmed_at' => $e['estado'] === 'confirmada' ? now()->subDays($e['dias']) : null,
                    'created_at' => now()->subDays($e['dias']),
                    'updated_at' => now()->subDays($e['dias']),
                ],
            );
        }
    }

    /**
     * Dos avisos a los clientes: uno que ya salió y uno sin estrenar.
     *
     * Las dos cosas que se venden solas en una droguería de barrio: el
     * domicilio cuando alguien está enfermo y no puede salir, y el precio de
     * lo que se toma todos los días.
     */
    private function promociones(int $negocio): void
    {
        $avisos = [
            [
                'texto' => 'Domicilio gratis en medicamentos hasta las 10 p. m. Si no puede salir, nosotros vamos.',
                'tipo' => Promotion::SIN_DESCUENTO,
                'pct' => null,
                'estado' => Promotion::ENVIADA,
                'enviada' => true,
            ],
            [
                'texto' => '15% en vitaminas y suplementos esta semana.',
                'tipo' => Promotion::PORCENTAJE,
                'pct' => 0.15,
                'estado' => Promotion::BORRADOR,
                'enviada' => false,
            ],
        ];

        $cuantos = DB::table('business_user_affiliations')->where('busines_id', $negocio)->count();

        foreach ($avisos as $a) {
            DB::table('promotions')->updateOrInsert(
                ['busines_id' => $negocio, 'description' => $a['texto']],
                [
                    'tipo' => $a['tipo'],
                    'percentage_discount' => $a['pct'],
                    'start_date' => now()->subDays(1),
                    'end_date' => now()->addDays(6),
                    'usos' => 0,
                    'sent_at' => $a['enviada'] ? now()->subDays(1) : null,
                    'recipients_count' => $a['enviada'] ? $cuantos : 0,
                    'created_by' => self::DUENA,
                    'state' => $a['estado'],
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subDays(1),
                ],
            );
        }
    }

    /** Un UUID estable a partir de un texto, para las llaves de intento. */
    private static function comoUuid(string $semilla): string
    {
        $h = md5('vecipaya-demo-drogueria|'.$semilla);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4),
            substr($h, 16, 4), substr($h, 20, 12),
        );
    }

    /**
     * La conversación que hablaba de leche deslactosada.
     *
     * OJO CON ESTO, QUE NO ES SÓLO EL TEXTO: `chats` NO TIENE `busines_id`.
     * Una conversación es entre dos PERSONAS, no entre un cliente y un
     * negocio, y `ChatController@getUserChats` no menciona el negocio ni una
     * vez. Por eso la dueña ve las mismas conversaciones esté mirando la
     * droguería o el restaurante: no hay nada por lo que filtrar.
     *
     * Acá sólo se arregla el texto, que es lo que se puede arreglar sembrando.
     * Lo otro es un cambio de modelo y queda anotado en el balance.
     */
    private function conversacion(): void
    {
        $arreglos = [
            '¿Tienen leche deslactosada?' => '¿Tienen acetaminofén de 500 para niño?',
            'Sí, de dos marcas. ¿Se la agrego al pedido?' => 'Sí, en jarabe. ¿Se lo agrego al pedido?',
        ];

        foreach ($arreglos as $viejo => $nuevo) {
            DB::table('messages')->where('content', $viejo)->update(['content' => $nuevo]);
        }
    }
}
