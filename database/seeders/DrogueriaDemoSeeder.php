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

        $norte = $this->segundaSede($negocio);

        $this->conversacion($negocio, $norte);

        $this->command?->info("Listo. «{$nombre}» (negocio {$negocio}) ya tiene:");
        $this->command?->line('  · '.DB::table('business_user_affiliations')->where('busines_id', $negocio)->count().' clientes afiliados');
        $this->command?->line('  · '.DB::table('store_credits')->where('busines_id', $negocio)->count().' cupos de fiado');
        $this->command?->line('  · '.DB::table('cash_deposits')->where('busines_id', $negocio)->count().' entregas de efectivo');
        $this->command?->line('  · '.DB::table('promotions')->where('busines_id', $negocio)->count().' promociones');

        if ($norte) {
            $nombreNorte = DB::table('business')->where('busines_id', $norte)->value('name');

            $this->command?->newLine();
            $this->command?->info("Y «{$nombreNorte}» (negocio {$norte}), la segunda sede:");
            $this->command?->line('  · '.DB::table('products_business')->where('busines_id', $norte)->count().' productos, con otros precios');
            $this->command?->line('  · '.DB::table('business_user_affiliations')->where('busines_id', $norte)->count().' clientes afiliados');
            $this->command?->line('  · '.DB::table('promotions')->where('busines_id', $norte)->count().' promoción');
        }
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
    private function conversacion(int $centro, ?int $norte): void
    {
        $arreglos = [
            '¿Tienen leche deslactosada?' => '¿Tienen acetaminofén de 500 para niño?',
            'Sí, de dos marcas. ¿Se la agrego al pedido?' => 'Sí, en jarabe. ¿Se lo agrego al pedido?',
        ];

        foreach ($arreglos as $viejo => $nuevo) {
            DB::table('messages')->where('content', $viejo)->update(['content' => $nuevo]);
        }

        /*
         * LAS QUE YA HABÍA SE QUEDAN EN LA SEDE DEL CENTRO.
         *
         * Nacieron antes de que `chats.busines_id` existiera, así que están
         * en nulo y la migración no las rellenó a propósito: con el dueño
         * teniendo dos locales no hay forma de saber a cuál le escribieron.
         * Acá sí se sabe, porque son datos de demostración y los ponemos
         * nosotros.
         */
        $suyos = DB::table('chat_participants as cp')
            ->join('user as u', 'u.user_id', '=', 'cp.user_id')
            ->where('cp.user_id', self::DUENA)
            ->pluck('cp.chat_id');

        DB::table('chats')
            ->whereIn('chat_id', $suyos)
            ->whereNull('busines_id')
            ->update(['busines_id' => $centro]);

        if (! $norte) {
            return;
        }

        /*
         * Y UNA EN LA SEDE NORTE CON LA MISMA PERSONA.
         *
         * Katherine ya tiene una conversación con la dueña en la sede del
         * centro. Ésta es la misma persona preguntándole al OTRO local, y
         * es el dato que enseña para qué sirvió todo esto: antes los dos
         * mensajes caían en el mismo hilo —el de la pareja de personas— y
         * ahora son dos conversaciones, cada una en su mostrador.
         */
        $katherine = (int) DB::table('user')
            ->where('email', 'katherine.ariza@demo.example.com')
            ->value('user_id');

        if (! $katherine) {
            return;
        }

        $chat = DB::table('chats')->where('busines_id', $norte)->value('chat_id');

        if (! $chat) {
            $chat = DB::table('chats')->insertGetId([
                'type' => 'private',
                'busines_id' => $norte,
                'created_at' => now()->subDays(3),
            ], 'chat_id');

            foreach ([[self::DUENA, 2], [$katherine, 1]] as [$uid, $rol]) {
                DB::table('chat_participants')->insert([
                    'chat_id' => $chat,
                    'user_id' => $uid,
                    'role_id' => $rol,
                    'joined_at' => now()->subDays(3),
                ]);
            }
        }

        $dialogo = [
            [$katherine, 1, '¿En la del norte tienen el tensiómetro?', 3],
            [self::DUENA, 2, 'Sí, nos quedan dos. ¿Se lo aparto?', 3],
            [$katherine, 1, 'Sí por favor, paso esta tarde.', 2],
        ];

        foreach ($dialogo as [$uid, $rol, $texto, $dias]) {
            DB::table('messages')->updateOrInsert(
                ['chat_id' => $chat, 'content' => $texto],
                ['user_id' => $uid, 'role_id' => $rol, 'created_at' => now()->subDays($dias)],
            );
        }
    }

    /**
     * LA SEGUNDA SEDE: el mismo dueño, otro mostrador.
     *
     * El negocio 4 era «Sabor Costeño Restaurante», que colgaba de la misma
     * dueña que la droguería. Una droguera con un restaurante al lado no es
     * imposible, pero como dato de prueba no sirve para nada: lo que hay que
     * poder mirar es un dueño con DOS LOCALES DEL MISMO OFICIO, que es el
     * caso real —una cadena pequeña de barrio— y el que destapa los fallos
     * de ámbito.
     *
     * Se conserva lo que ya colgaba de él: 15 pedidos y 6 reseñas, con textos
     * genéricos («Todo llegó completo y bien empacado») que valen igual para
     * una droguería. Borrarlos y rehacerlos sería tirar datos buenos.
     *
     * --------------------------------------------------------------------
     * Y SU SURTIDO ES DISTINTO, QUE ES EL PUNTO.
     *
     * Dos sedes con el mismo inventario y los mismos precios no enseñan
     * nada: no se distingue «el panel está mostrando la sede correcta» de
     * «el panel está mostrando siempre la misma». Ésta tiene la mitad del
     * surtido, precios algo más altos —es otro barrio— y dos cosas agotadas
     * que en el centro sí hay.
     */
    private function segundaSede(int $centro): ?int
    {
        $norte = (int) DB::table('business')
            ->where('busines_id', '<>', $centro)
            ->whereIn('busines_id', function ($q) {
                $q->select('ob.busines_id')
                    ->from('owner_busines as ob')
                    ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                    ->where('o.user_id', self::DUENA);
            })
            ->orderBy('busines_id')
            ->value('busines_id');

        if (! $norte) {
            return null;
        }

        DB::table('business')->where('busines_id', $norte)->update([
            'name' => 'Droguería Vida Sana Norte',
            /*
             * Su razón social, no la que traía: el negocio 4 nació como
             * «Sabor Costeño Restaurante» en DemoSeeder y conservaba «Sabor
             * Costeño Restaurante S.A.S.», que Mi negocio enseñaba como la de
             * la droguería. El NIT (DEMO-003) se queda: es la llave con la que
             * DemoSeeder lo encuentra.
             */
            'razonSocial_DCD' => 'Droguería Vida Sana Norte S.A.S.',
            'type' => ProductTypeSchema::TYPE_PHARMACY,
            'address' => 'Calle 85 #51B-22',
            'description' => 'La sede del norte. Mismo surtido, abierta hasta las 11 p. m.',
            'credit_debt_cap' => 250000,
        ]);

        $this->surtidoDelNorte($centro, $norte);

        /* Cuatro clientes, y NINGUNO repetido del centro salvo Katherine:
           así se ve de un vistazo que las dos listas son distintas. */
        foreach ([33, 35, 36, 42] as $uid) {
            if (DB::table('user')->where('user_id', $uid)->exists()) {
                DB::table('business_user_affiliations')->updateOrInsert(
                    ['busines_id' => $norte, 'user_id' => $uid],
                    [],
                );
            }
        }

        DB::table('store_credits')->updateOrInsert(
            ['busines_id' => $norte, 'user_id' => 35],
            [
                'credit_limit' => 50000,
                'created_by' => self::DUENA,
                'created_at' => now()->subDays(5),
                'updated_at' => now(),
            ],
        );

        DB::table('promotions')->updateOrInsert(
            ['busines_id' => $norte, 'description' => 'Abierto hasta las 11 p. m. Domicilio en 20 minutos.'],
            [
                'tipo' => Promotion::SIN_DESCUENTO,
                'percentage_discount' => null,
                'start_date' => now()->subDays(1),
                'end_date' => now()->addDays(13),
                'usos' => 0,
                'sent_at' => now()->subDays(1),
                'recipients_count' => 4,
                'created_by' => self::DUENA,
                'state' => Promotion::ENVIADA,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ],
        );

        return $norte;
    }

    /**
     * La mitad del surtido del centro, con otros precios.
     *
     * El catálogo de productos es COMPARTIDO: la sede norte no crea
     * productos nuevos, se cuelga de los mismos. Lo que cambia por sede es
     * precio y existencias, que es justo lo que vive en `products_business`.
     * Crear productos duplicados para la otra sede sería ensuciar el
     * catálogo de toda la plataforma para pintar una pantalla.
     */
    private function surtidoDelNorte(int $centro, int $norte): void
    {
        $delCentro = DB::table('products_business')
            ->where('busines_id', $centro)
            ->orderBy('products_id')
            ->get(['products_id', 'price', 'sale_unit']);

        if ($delCentro->isEmpty()) {
            return;
        }

        /* Uno de cada dos: la sede chica no trae todo el catálogo. */
        $suyos = $delCentro->values()->filter(fn ($p, $i) => $i % 2 === 0)->values();

        foreach ($suyos as $i => $p) {
            /* Un 8 % más caro, redondeado a la centena: es otro barrio, y
               dos sedes con el precio idéntico al peso no se distinguen. */
            $precio = round(((float) $p->price * 1.08) / 100) * 100;

            /* Las dos primeras, agotadas. Una sede sin NADA agotado no
               enseña el contador de «Agotados», que es media pantalla. */
            $cantidad = $i < 2 ? 0 : (6 + ($i * 3) % 25);

            DB::table('products_business')->updateOrInsert(
                ['busines_id' => $norte, 'products_id' => (int) $p->products_id],
                [
                    'price' => $precio,
                    'sale_unit' => $p->sale_unit,
                    'amount' => $cantidad,
                    'stock_weight' => 0,
                    'qualification' => 0,
                    'created_at' => now()->subDays(6),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
