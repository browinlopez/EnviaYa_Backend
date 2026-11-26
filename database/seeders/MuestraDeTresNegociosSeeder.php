<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Operacion\CashDeposit;
use App\Models\Operacion\CashMovement;
use App\Models\Order\OrdersSales;
use App\Models\Promotion;
use App\Services\Ajustes;
use App\Services\CobroContraEntrega;
use App\Services\CreditoDeTienda;
use App\Services\CustodiaDeEfectivo;
use App\Services\PinDeEntrega;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * ==========================================================================
 * TRES NEGOCIOS CON DOS MESES DE VIDA, PARA ENSEÑAR EL PANEL ENTERO.
 *
 * Los seeders de demostración que ya hay llenan cada uno una pantalla: los
 * pedidos en curso, el efectivo, la droguería, la carta del restaurante. Lo
 * que no hay es HISTORIA: el tablero de «esta semana» sale con dos barras,
 * «Cuánto te queda» no tiene costos contra qué restar, y el restaurante tiene
 * dos pedidos en toda su vida. Una demostración del panel con eso enseña
 * pantallas vacías, y una pantalla vacía no se distingue de una rota.
 *
 * Esto siembra, para cada uno de los tres negocios de la demostración:
 *
 *   1  «Tienda de prueba»            tienda de barrio
 *   4  «Droguería Vida Sana Norte»   droguería
 *   9  «Sabor Costeño Restaurante»   restaurante
 *
 *   · ~60 días de pedidos con sus renglones, del surtido REAL de ese negocio y
 *     a sus precios, más los del fin de semana y la quincena;
 *   · pedidos de hoy en cada estado vivo, uno de ellos con más de media hora
 *     sin aceptar, y los que van en camino con su rastro en el mapa;
 *   · reseñas sobre pedidos entregados, con textos DEL OFICIO;
 *   · clientes afiliados y fiado: unos deben, otros están al día;
 *   · promociones (una vigente, una con tope de usos, una ya agotada…);
 *   · gastos fijos del local, costo de los más vendidos y ventas de mostrador;
 *   · el efectivo cobrado y entregado por el MISMO camino que producción:
 *     `CobroContraEntrega::registrar` al entregar y
 *     `CustodiaDeEfectivo::recibirEnMostrador` al cerrar el día.
 *
 * --------------------------------------------------------------------------
 * SE PUEDE CORRER LAS VECES QUE HAGA FALTA.
 *
 * Los pedidos no tienen llave natural, así que lo que este seeder crea queda
 * apuntado en una libreta (`storage/app/private/semillas/…json`) y la
 * siguiente corrida lo retira ANTES de volver a sembrar. Lo de «hoy» se calcula
 * contra la hora de la corrida: correrlo justo antes de la demostración deja
 * el día lleno hasta ese momento.
 *
 * Los compradores son todos de `@demo.example.com`, así que `DemoPurgeSeeder`
 * también se lleva estos pedidos. Para retirar TODO lo de aquí —también los
 * vecinos nuevos, las afiliaciones, los cupos y los costos puestos—:
 *
 *     MUESTRA_PURGAR=1 php artisan db:seed --class=MuestraDeTresNegociosSeeder
 *
 * SÓLO PARA DESARROLLO. No lo llama `DatabaseSeeder`.
 * ========================================================================== */
class MuestraDeTresNegociosSeeder extends Seeder
{
    /** Semilla fija: dos corridas el mismo día dan los mismos importes. */
    private const SEMILLA = 20261126;

    /** Cuántos días hacia atrás. */
    private const DIAS = 60;

    private const TIENDA = 'tienda';

    private const DROGUERIA = 'drogueria';

    private const RESTAURANTE = 'restaurante';

    /** negocio => [oficio, user_id de quien atiende]. */
    private const NEGOCIOS = [
        1 => [self::TIENDA, 4],
        4 => [self::DROGUERIA, 20],
        9 => [self::RESTAURANTE, 49],
    ];

    /** Los domiciliarios de cada negocio (los que ya existen en la base). */
    private const FLOTAS = [
        1 => [1, 2, 10, 11, 12, 13],
        4 => [2, 4, 5],
        9 => [3, 4, 5, 9],
    ];

    /* Medios de pago (`payment_methods`) y formas (`payment_forms`). */
    private const EFECTIVO = 1;

    private const TARJETA = 2;

    private const TRANSFERENCIA = 4;

    private const QR = 5;

    private const NEQUI = 6;

    private const FIADO = CreditoDeTienda::METODO;

    private const CONTRA_ENTREGA = 1;

    private const ANTICIPADO = 2;

    /**
     * Vecinos que faltaban. Con doce compradores para tres negocios y dos
     * meses, la lista de Clientes salía con las mismas caras en todas partes.
     */
    private const VECINOS_NUEVOS = [
        ['Yulieth Charris', 'Calle 79 #42F-15'],
        ['Carlos Ahumada', 'Carrera 46 #70-112'],
        ['Mónica Pacheco', 'Calle 84 #50-31'],
        ['Andrés Fontalvo', 'Carrera 41 #72-08'],
        ['Liliana De la Hoz', 'Calle 76 #38-44'],
        ['Kelly Mercado', 'Carrera 43 #74-19'],
        ['Jhon Jairo Rada', 'Calle 70 #44-27'],
        ['Paola Salcedo', 'Carrera 52 #86-40'],
        ['Hernando Villa', 'Calle 73 #39-56'],
        ['Estefanía Rúa', 'Carrera 49C #82-11'],
        ['Rafael Caballero', 'Calle 68 #41-33'],
        ['Nayibe Torres', 'Carrera 38 #69-90'],
        ['Luis Fernando Bolaño', 'Calle 87 #47-25'],
        ['Diana Pérez', 'Carrera 44 #77-60'],
        ['Álvaro Navarro', 'Calle 75 #45-18'],
        ['Yesenia Coronado', 'Carrera 50 #80-07'],
    ];

    /**
     * La clientela de cada negocio, por la parte del correo antes de la @.
     * Los primeros quedan afiliados; los de `FIADOS` llevan cupo.
     */
    private const CLIENTELA = [
        1 => [
            'laura.mendoza', 'jorge.barrios', 'katherine.ariza', 'emilio.zuniga', 'sofia.herrera',
            'yulieth.charris', 'liliana.de.la.hoz', 'rafael.caballero', 'nayibe.torres', 'diana.perez',
            'wilson.tamara', 'rosa.iriarte', 'alvaro.navarro', 'paola.salcedo', 'fabian.movilla',
            'mariana.vengoechea', 'estefania.rua', 'ivan.padilla',
        ],
        4 => [
            'katherine.ariza', 'daniela.consuegra', 'wilson.tamara', 'ivan.padilla', 'monica.pacheco',
            'estefania.rua', 'luis.fernando.bolano', 'paola.salcedo', 'yesenia.coronado', 'alberto.cantillo',
            'mariana.vengoechea', 'rosa.iriarte', 'laura.mendoza', 'diana.perez',
        ],
        9 => [
            'carlos.ahumada', 'andres.fontalvo', 'jhon.jairo.rada', 'kelly.mercado', 'hernando.villa',
            'liliana.de.la.hoz', 'alvaro.navarro', 'fabian.movilla', 'jorge.barrios', 'yesenia.coronado',
            'emilio.zuniga', 'nayibe.torres', 'rafael.caballero', 'sofia.herrera', 'luis.fernando.bolano',
            'daniela.consuegra',
        ],
    ];

    /** Cuántos de la clientela quedan afiliados. */
    private const AFILIADOS = [1 => 14, 4 => 11, 9 => 13];

    /**
     * Cupo de fiado por cliente. Montos del oficio: en la tienda el mercado
     * de la quincena, en la droguería el remedio de la semana, en el
     * restaurante el almuerzo de los que trabajan cerca y pagan el 15 y el 30.
     */
    private const FIADOS = [
        1 => ['sofia.herrera' => 200000, 'yulieth.charris' => 150000],
        4 => ['katherine.ariza' => 60000, 'wilson.tamara' => 40000, 'ivan.padilla' => 35000, 'monica.pacheco' => 45000],
        9 => [
            'carlos.ahumada' => 150000, 'andres.fontalvo' => 120000, 'jhon.jairo.rada' => 100000,
            'kelly.mercado' => 90000, 'hernando.villa' => 80000,
        ],
    ];

    /** Tope de fiado del negocio, sólo si no tenía. */
    private const TOPES = [9 => 600000];

    private string $rutaLibreta;

    /** @var array<string, mixed> */
    private array $libreta;

    private Carbon $ahora;

    private float $tarifa;

    private float $reparto;

    private float $comision;

    private CobroContraEntrega $cobro;

    private CustodiaDeEfectivo $custodia;

    private CreditoDeTienda $credito;

    /** @var array<string, array{user_id:int, buyer_id:int, address_id:?int, lat:float, lng:float}> */
    private array $vecinos = [];

    public function run(): void
    {
        if (app()->isProduction() && ! config('semillas.permitir_en_produccion')) {
            $this->command?->warn('MuestraDeTresNegociosSeeder no corre en producción: son datos falsos.');

            return;
        }

        mt_srand(self::SEMILLA);

        $this->ahora = now();
        $this->rutaLibreta = storage_path('app/private/semillas/muestra-tres-negocios.json');
        $this->libreta = $this->leerLibreta();

        $this->tarifa = (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000);
        $this->reparto = (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75);
        $this->comision = (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03);

        $this->cobro = app(CobroContraEntrega::class);
        $this->custodia = app(CustodiaDeEfectivo::class);
        $this->credito = app(CreditoDeTienda::class);

        $purgar = filter_var(env('MUESTRA_PURGAR', false), FILTER_VALIDATE_BOOL);

        /* Lo de la corrida anterior se va primero: así no se duplica nada. */
        DB::transaction(fn () => $this->retirarLoSembrado($purgar));
        $this->guardarLibreta();

        if ($purgar) {
            $this->recalcularCalificaciones();
            $this->command?->info('Retirado todo lo que sembró MuestraDeTresNegociosSeeder.');

            return;
        }

        $this->prepararVecinos();
        $this->guardarLibreta();

        foreach (self::NEGOCIOS as $negocio => [$oficio, $dueno]) {
            if (! DB::table('business')->where('busines_id', $negocio)->exists()) {
                $this->command?->warn("No existe el negocio {$negocio}; se omite.");

                continue;
            }

            $this->command?->info('Sembrando «'.DB::table('business')->where('busines_id', $negocio)->value('name').'»…');

            DB::transaction(fn () => $this->sembrarNegocio($negocio, $oficio, $dueno));
            $this->guardarLibreta();
        }

        $this->recalcularCalificaciones();
        $this->resumen();
    }

    /* ================================================================== */
    /* LA LIBRETA: lo que se creó, para poderlo retirar */

    /** @return array<string, mixed> */
    private function leerLibreta(): array
    {
        $vacia = [
            // Se rehacen en cada corrida.
            'pedidos' => [], 'resenas' => [], 'resenas_domi' => [], 'ventas' => [],
            'gastos' => [], 'promociones' => [], 'depositos' => [], 'abonos' => [], 'posiciones' => [],
            // Los costos se devuelven y se vuelven a poner en cada corrida.
            'costos' => [],
            // Se quedan entre corridas; sólo se van con MUESTRA_PURGAR=1.
            'usuarios' => [], 'afiliaciones' => [], 'creditos' => [], 'vinculos' => [], 'topes' => [],
        ];

        if (! File::exists($this->rutaLibreta)) {
            return $vacia;
        }

        $leida = json_decode((string) File::get($this->rutaLibreta), true);

        return is_array($leida) ? array_merge($vacia, $leida) : $vacia;
    }

    private function guardarLibreta(): void
    {
        File::ensureDirectoryExists(dirname($this->rutaLibreta));
        File::put($this->rutaLibreta, json_encode($this->libreta, JSON_PRETTY_PRINT));
    }

    /**
     * Retira lo de la corrida anterior, en el orden en que cuelga una cosa
     * de la otra. Con `$todo` también lo que normalmente se conserva.
     */
    private function retirarLoSembrado(bool $todo): void
    {
        $l = &$this->libreta;

        foreach (array_chunk($l['pedidos'], 500) as $ids) {
            DB::table('invoices')->whereIn('orderSales_id', $ids)->delete();
            DB::table('settlement_items')->whereIn('order_id', $ids)->delete();
            DB::table('payments')->whereIn('orderSales_id', $ids)->delete();
            DB::table('orders_promotions')->whereIn('orderSales_id', $ids)->delete();
            DB::table('order_geolocation')->whereIn('orderSales_id', $ids)->delete();
            DB::table('store_credit_movements')->whereIn('order_id', $ids)->delete();
            DB::table('cash_movements')->whereIn('order_id', $ids)->delete();
            DB::table('orderssales_detail')->whereIn('orderSales_id', $ids)->delete();
            DB::table('orderssales')->whereIn('orderSales_id', $ids)->delete();
        }

        DB::table('cash_movements')->whereIn('deposit_id', $l['depositos'])->delete();
        DB::table('cash_deposits')->whereIn('id', $l['depositos'])->delete();
        DB::table('store_credit_movements')->whereIn('id', $l['abonos'])->delete();
        DB::table('business_reviews')->whereIn('reviews_id', $l['resenas'])->delete();
        DB::table('domiciliary_reviews')->whereIn('reviews_id', $l['resenas_domi'])->delete();
        DB::table('order_geolocation')->whereIn('geolocation_id', $l['posiciones'])->delete();

        foreach (array_chunk($l['ventas'], 500) as $ids) {
            DB::table('ventas_mostrador_lineas')->whereIn('venta_id', $ids)->delete();
            DB::table('ventas_mostrador')->whereIn('venta_id', $ids)->delete();
        }

        DB::table('gastos_del_mes')->whereIn('gasto_id', $l['gastos'])->delete();
        DB::table('gastos_del_negocio')->whereIn('gasto_id', $l['gastos'])->delete();

        DB::table('promotion_products')->whereIn('promotion_id', $l['promociones'])->delete();
        DB::table('orders_promotions')->whereIn('promotion_id', $l['promociones'])->delete();
        DB::table('promotions')->whereIn('promotion_id', $l['promociones'])->delete();

        /* Los costos vuelven a como estaban: la corrida los pone otra vez. */
        foreach ($l['costos'] as $pb => $anterior) {
            DB::table('products_business')->where('busines_products_id', (int) $pb)->update(['costo' => $anterior]);
        }

        foreach (['pedidos', 'resenas', 'resenas_domi', 'ventas', 'gastos', 'promociones', 'depositos', 'abonos', 'posiciones', 'costos'] as $k) {
            $l[$k] = [];
        }

        if (! $todo) {
            return;
        }

        /* --- Lo que se conservaba entre corridas --- */
        DB::table('store_credit_movements')->whereIn('store_credit_id', $l['creditos'])->delete();
        DB::table('store_credits')->whereIn('id', $l['creditos'])->delete();

        foreach ($l['afiliaciones'] as [$negocio, $usuario]) {
            DB::table('business_user_affiliations')->where('busines_id', $negocio)->where('user_id', $usuario)->delete();
        }

        foreach ($l['vinculos'] as [$negocio, $domi]) {
            DB::table('business_domiciliary')->where('busines_id', $negocio)->where('domiciliary_id', $domi)->delete();
        }

        foreach ($l['topes'] as $negocio => $anterior) {
            DB::table('business')->where('busines_id', (int) $negocio)->update(['credit_debt_cap' => $anterior]);
        }

        $quedan = [];

        foreach ($l['usuarios'] as $userId) {
            $buyer = DB::table('buyer')->where('user_id', $userId)->value('buyer_id');

            /* Si alguien le hizo un pedido desde la app, no se borra a ciegas. */
            if ($buyer && DB::table('orderssales')->where('buyer_id', $buyer)->exists()) {
                $this->command?->warn("El vecino {$userId} tiene pedidos que no son de esta semilla; se deja.");
                $quedan[] = $userId;

                continue;
            }

            if ($buyer) {
                DB::table('business_reviews')->where('buyer_id', $buyer)->delete();
                DB::table('domiciliary_reviews')->where('buyer_id', $buyer)->delete();
                DB::table('buyer_complex')->where('buyer_id', $buyer)->delete();
                DB::table('buyer')->where('buyer_id', $buyer)->delete();
            }

            DB::table('business_user_affiliations')->where('user_id', $userId)->delete();
            DB::table('user_address')->where('user_id', $userId)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->where('tokenable_id', $userId)
                ->delete();
            DB::table('user')->where('user_id', $userId)->delete();
        }

        $l['usuarios'] = $quedan;
        $l['creditos'] = $l['afiliaciones'] = $l['vinculos'] = [];
        $l['topes'] = [];
    }

    /* ================================================================== */
    /* LOS VECINOS */

    private function prepararVecinos(): void
    {
        foreach (self::VECINOS_NUEVOS as $i => [$nombre, $direccion]) {
            $usuario = strtolower(str_replace(' ', '.', $this->sinTildes($nombre)));
            $correo = "{$usuario}@".DemoSeeder::DOMINIO;

            $userId = DB::table('user')->where('email', $correo)->value('user_id');

            if (! $userId) {
                $userId = DB::table('user')->insertGetId([
                    'name' => $nombre,
                    'email' => $correo,
                    'password' => Hash::make(DemoSeeder::CLAVE),
                    'phone' => '30'.mt_rand(0, 9).' '.mt_rand(200, 899).' '.mt_rand(1000, 9999),
                    'address' => $direccion,
                    'rol' => 1,
                    'qualification' => round(mt_rand(40, 50) / 10, 2),
                    'state' => 1,
                    'email_verified_at' => now(),
                    'created_at' => now()->subDays(self::DIAS + mt_rand(5, 120)),
                    'updated_at' => now(),
                ], 'user_id');

                $this->libreta['usuarios'][] = (int) $userId;
            }

            DB::table('buyer')->updateOrInsert(
                ['user_id' => $userId],
                ['belongs_to_complex' => 0, 'state' => 1],
            );

            DB::table('user_address')->updateOrInsert(
                ['user_id' => $userId, 'address' => $direccion],
                [
                    /* Alrededor de la 72 con 44: donde quedan los tres negocios. */
                    'latitude' => 10.9930 + mt_rand(-140, 140) / 10000,
                    'longitude' => -74.7990 + mt_rand(-140, 140) / 10000,
                    'municipality_id' => 16,
                    'state' => 1,
                ],
            );
        }

        $filas = DB::table('user as u')
            ->join('buyer as b', 'b.user_id', '=', 'u.user_id')
            ->where('u.email', 'like', '%@'.DemoSeeder::DOMINIO)
            ->where('u.rol', 1)
            ->get(['u.user_id', 'u.email', 'b.buyer_id']);

        foreach ($filas as $f) {
            $dir = DB::table('user_address')->where('user_id', $f->user_id)->orderBy('address_id')->first();

            $this->vecinos[strstr((string) $f->email, '@', true)] = [
                'user_id' => (int) $f->user_id,
                'buyer_id' => (int) $f->buyer_id,
                'address_id' => $dir ? (int) $dir->address_id : null,
                'lat' => $dir && $dir->latitude ? (float) $dir->latitude : 10.99,
                'lng' => $dir && $dir->longitude ? (float) $dir->longitude : -74.80,
            ];
        }
    }

    /* ================================================================== */
    /* UN NEGOCIO */

    private function sembrarNegocio(int $negocio, string $oficio, int $dueno): void
    {
        /* Semilla por negocio: que la primera corrida haya creado vecinos
           (y gastado números al azar) no cambia lo que sale después. */
        mt_srand(self::SEMILLA + $negocio);

        $flota = $this->flota($negocio);
        $surtido = $this->surtido($negocio, $oficio);

        if ($surtido === []) {
            $this->command?->warn("  Negocio {$negocio} sin surtido vendible; se omite.");

            return;
        }

        $clientes = $this->clientes($negocio);
        $this->fiado($negocio, $dueno);
        $promos = $this->promociones($negocio, $oficio, $dueno, $surtido);
        $this->gastos($negocio, $oficio);

        $this->historia($negocio, $oficio, $dueno, $flota, $surtido, $clientes, $promos);
        $this->clienteAlDia($negocio, $dueno);
        $this->enCursoHoy($negocio, $oficio, $flota, $surtido, $clientes);
        $this->mostrador($negocio, $oficio, $dueno, $surtido);
        $this->resenas($negocio, $oficio);
        $this->usosDePromociones($promos);
    }

    /** @return list<int> Los domiciliarios del negocio que pueden repartir. */
    private function flota(int $negocio): array
    {
        foreach (self::FLOTAS[$negocio] as $domi) {
            if (! DB::table('domiciliary')->where('domiciliary_id', $domi)->exists()) {
                continue;
            }

            $ya = DB::table('business_domiciliary')
                ->where('busines_id', $negocio)->where('domiciliary_id', $domi)->exists();

            if (! $ya) {
                DB::table('business_domiciliary')->insert([
                    'busines_id' => $negocio, 'domiciliary_id' => $domi, 'state' => 1,
                ]);
                $this->libreta['vinculos'][] = [$negocio, $domi];
            }
        }

        /* Sólo los que tienen la cuenta activa y el acuerdo firmado. */
        return DB::table('business_domiciliary as bd')
            ->join('domiciliary as d', 'd.domiciliary_id', '=', 'bd.domiciliary_id')
            ->join('user as u', 'u.user_id', '=', 'd.user_id')
            ->where('bd.busines_id', $negocio)
            ->where('bd.state', 1)
            ->where('u.state', 1)
            ->whereNotNull('d.contract_signed_at')
            ->orderBy('bd.domiciliary_id')
            ->pluck('bd.domiciliary_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Lo que se vende, con cuánto se vende. El orden es la popularidad: los
     * primeros salen en casi todos los pedidos y los últimos de vez en cuando,
     * que es lo que hace que «Más vendidos» tenga una cabeza clara.
     *
     * Y EL COSTO de los que más se venden, para que «Costos» y «Cuánto te
     * queda» tengan margen que enseñar. Uno de cada seis se deja sin costo a
     * propósito: el aviso de «productos sin costo» también es pantalla.
     *
     * @return list<array{id:int, pb:int, nombre:string, precio:float, costo:?float, peso:int, grupo:string}>
     */
    private function surtido(int $negocio, string $oficio): array
    {
        $filas = DB::table('products_business as pb')
            ->join('products as p', 'p.products_id', '=', 'pb.products_id')
            ->where('pb.busines_id', $negocio)
            ->where('pb.price', '>', 0)
            ->where('pb.amount', '>', 0)
            ->where('pb.sale_unit', 'unidad')
            ->orderBy('pb.products_id')
            ->get(['pb.busines_products_id', 'pb.products_id', 'pb.price', 'pb.costo', 'p.name']);

        if ($filas->isEmpty()) {
            return [];
        }

        $lista = [];

        if ($oficio === self::RESTAURANTE) {
            foreach ($filas as $f) {
                [$grupo, $peso] = $this->platoDeLaCarta((string) $f->name);
                $lista[] = $this->renglonDelSurtido($f, $grupo, $peso);
            }

            usort($lista, fn ($a, $b) => $b['peso'] <=> $a['peso']);
        } else {
            /* Barajado con la semilla, y los primeros son los que más salen. */
            $todos = $filas->all();
            $this->barajar($todos);
            $resto = array_slice($todos, $oficio === self::TIENDA ? 70 : 80);
            $todos = array_slice($todos, 0, $oficio === self::TIENDA ? 70 : 80);

            foreach ($todos as $i => $f) {
                $lista[] = $this->renglonDelSurtido($f, 'general', (int) round(1000 / (($i + 1) ** 0.85)));
            }

            /* Lo que entra en las promociones tiene que poderse vender. */
            foreach ($resto as $f) {
                foreach (['PONY MALTA', 'centrum', 'multivit', 'vitamin', 'ensure', 'glucerna', 'beb', 'racimos'] as $patron) {
                    if (mb_stripos((string) $f->name, $patron) !== false) {
                        $lista[] = $this->renglonDelSurtido($f, 'general', 40);
                        break;
                    }
                }
            }
        }

        foreach ($lista as $i => &$p) {
            /* En el restaurante se dejan sin costo los acompañamientos sueltos. */
            $sinCosto = $oficio === self::RESTAURANTE
                ? in_array($p['nombre'], ['Porción de arroz', 'Porción de patacón', 'Ensalada de la casa'], true)
                : $i % 6 === 4;

            if ($p['costo'] !== null || $sinCosto) {
                continue;
            }

            $factor = match ($oficio) {
                self::TIENDA => mt_rand(70, 82) / 100,
                self::DROGUERIA => mt_rand(58, 72) / 100,
                default => match ($p['grupo']) {
                    'bebida' => mt_rand(30, 45) / 100,
                    'postre' => mt_rand(30, 40) / 100,
                    default => mt_rand(34, 45) / 100,
                },
            };

            if ($oficio === self::RESTAURANTE && str_contains($p['nombre'], 'Gaseosa')) {
                $factor = 0.6;
            }

            $costo = round($p['precio'] * $factor / 50) * 50;

            if (! array_key_exists((string) $p['pb'], $this->libreta['costos'])) {
                $this->libreta['costos'][(string) $p['pb']] = null;
            }

            DB::table('products_business')->where('busines_products_id', $p['pb'])->update(['costo' => $costo]);
            $p['costo'] = (float) $costo;
        }
        unset($p);

        return $lista;
    }

    private function renglonDelSurtido(object $f, string $grupo, int $peso): array
    {
        return [
            'id' => (int) $f->products_id,
            'pb' => (int) $f->busines_products_id,
            'nombre' => (string) $f->name,
            'precio' => (float) $f->price,
            'costo' => $f->costo !== null ? (float) $f->costo : null,
            'peso' => max(1, $peso),
            'grupo' => $grupo,
        ];
    }

    /** @return array{0:string, 1:int} [momento del día, popularidad] */
    private function platoDeLaCarta(string $nombre): array
    {
        $carta = [
            'Corrientazo del día' => ['almuerzo', 30], 'Bandeja paisa' => ['almuerzo', 18],
            'Sancocho de gallina' => ['almuerzo', 16], 'Arroz con pollo' => ['almuerzo', 15],
            'Mojarra frita' => ['almuerzo', 11], 'Mondongo' => ['almuerzo', 9],
            'Cazuela de mariscos' => ['almuerzo', 6], 'Ensalada de la casa' => ['almuerzo', 4],
            'Wrap vegano de garbanzo' => ['almuerzo', 3], 'Bowl de quinoa y aguacate' => ['almuerzo', 4],
            'Calentado con huevo' => ['desayuno', 12], 'Huevos pericos' => ['desayuno', 9],
            'Arepa de huevo' => ['desayuno', 10], 'Caldo de costilla' => ['desayuno', 7],
            'Pollo asado entero' => ['cena', 12], 'Chuzo de res' => ['cena', 10],
            'Hamburguesa sencilla' => ['cena', 14], 'Perro caliente' => ['cena', 12],
            'Patacón con todo' => ['cena', 11],
            'Jugo natural en agua' => ['bebida', 20], 'Gaseosa personal' => ['bebida', 16],
            'Limonada de coco' => ['bebida', 14], 'Jugo de lulo en leche' => ['bebida', 8],
            'Flan de caramelo' => ['postre', 6], 'Arroz con leche' => ['postre', 5], 'Tres leches' => ['postre', 7],
            'Porción de arroz' => ['adicional', 6], 'Porción de patacón' => ['adicional', 8],
        ];

        return $carta[$nombre] ?? ['almuerzo', 2];
    }

    /** @return array<string, int> correo => peso (los afiliados piden más) */
    private function clientes(int $negocio): array
    {
        $pesos = [];

        foreach (self::CLIENTELA[$negocio] as $i => $clave) {
            if (! isset($this->vecinos[$clave])) {
                continue;
            }

            $afiliado = $i < self::AFILIADOS[$negocio];

            if ($afiliado) {
                $uid = $this->vecinos[$clave]['user_id'];
                $ya = DB::table('business_user_affiliations')
                    ->where('busines_id', $negocio)->where('user_id', $uid)->exists();

                if (! $ya) {
                    DB::table('business_user_affiliations')->insert(['busines_id' => $negocio, 'user_id' => $uid]);
                    $this->libreta['afiliaciones'][] = [$negocio, $uid];
                }
            }

            /* Los de la casa piden mucho más que los de paso. */
            $pesos[$clave] = $afiliado ? 4 + (int) (12 / ($i + 1)) : 1;
        }

        return $pesos;
    }

    /** El tope del negocio, si no tenía, y los cupos por el servicio de verdad. */
    private function fiado(int $negocio, int $dueno): void
    {
        if (isset(self::TOPES[$negocio])) {
            $actual = DB::table('business')->where('busines_id', $negocio)->value('credit_debt_cap');

            if ($actual === null) {
                $this->libreta['topes'][(string) $negocio] = null;
                DB::table('business')->where('busines_id', $negocio)->update(['credit_debt_cap' => self::TOPES[$negocio]]);
            }
        }

        foreach (self::FIADOS[$negocio] as $clave => $cupo) {
            $uid = $this->vecinos[$clave]['user_id'] ?? null;

            if (! $uid) {
                continue;
            }

            $existia = DB::table('store_credits')->where('busines_id', $negocio)->where('user_id', $uid)->exists();

            try {
                $credito = $this->credito->asignar($negocio, $uid, (float) $cupo, $dueno);
            } catch (Throwable $e) {
                $this->command?->warn("  No se pudo dar cupo a {$clave}: {$e->getMessage()}");

                continue;
            }

            if (! $existia) {
                $this->libreta['creditos'][] = (int) $credito->id;
                DB::table('store_credits')->where('id', $credito->id)->update([
                    'created_at' => $this->ahora->copy()->subDays(self::DIAS + 3),
                ]);
            }
        }
    }

    /**
     * Las promociones de cada oficio. Una vigente con descuento, una con tope
     * de usos, una que ya se agotó o un borrador: los estados que la pantalla
     * pinta distinto.
     *
     * @return list<array{id:int, tipo:string, pct:?float, lleva:?int, paga:?int, productos:list<int>, desde:Carbon, hasta:Carbon, max:?int, usos:int}>
     */
    private function promociones(int $negocio, string $oficio, int $dueno, array $surtido): array
    {
        $hoy = $this->ahora->copy()->startOfDay();
        $domingo = $hoy->copy()->endOfWeek(Carbon::SUNDAY);

        $buscar = function (array $patrones) use ($surtido): array {
            $ids = [];
            foreach ($surtido as $p) {
                foreach ($patrones as $patron) {
                    if (mb_stripos($p['nombre'], $patron) !== false) {
                        $ids[] = $p['id'];
                        break;
                    }
                }
            }

            return array_values(array_unique($ids));
        };

        $defs = match ($oficio) {
            self::TIENDA => [
                [
                    'texto' => 'Lleva 3 Pony Malta y paga 2. Hasta el domingo o hasta agotar 40 combos.',
                    'tipo' => Promotion::NXM, 'pct' => null, 'lleva' => 3, 'paga' => 2,
                    'productos' => $buscar(['PONY MALTA']),
                    'desde' => $hoy->copy()->subDays(6), 'hasta' => $domingo, 'max' => 40, 'estado' => Promotion::ENVIADA,
                ],
            ],
            self::DROGUERIA => [
                [
                    'texto' => 'Vitaminas y suplementos con 10% de descuento hasta el domingo.',
                    'tipo' => Promotion::PORCENTAJE, 'pct' => 0.10, 'lleva' => null, 'paga' => null,
                    'productos' => $buscar(['centrum', 'multivit', 'vitamin', 'ensure', 'glucerna']),
                    'desde' => $hoy->copy()->subDays(8), 'hasta' => $domingo, 'max' => null, 'estado' => Promotion::ENVIADA,
                ],
                [
                    'texto' => 'Cuidado del bebé: lleva 3 y paga 2 en jabones, shampoo y cremas.',
                    'tipo' => Promotion::NXM, 'pct' => null, 'lleva' => 3, 'paga' => 2,
                    'productos' => $buscar(['beb', 'racimos']),
                    'desde' => $hoy->copy()->subDays(40), 'hasta' => $hoy->copy()->subDays(24)->endOfDay(), 'max' => 20,
                    'estado' => Promotion::ENVIADA,
                ],
            ],
            default => [
                [
                    'texto' => 'Perro caliente 2x1 en domicilios, hasta fin de mes.',
                    'tipo' => Promotion::NXM, 'pct' => null, 'lleva' => 2, 'paga' => 1,
                    'productos' => $buscar(['Perro caliente']),
                    'desde' => $hoy->copy()->subDays(18), 'hasta' => $hoy->copy()->endOfMonth(), 'max' => 60,
                    'estado' => Promotion::ENVIADA,
                ],
                [
                    'texto' => '15% en cazuela de mariscos y mojarra frita hasta el domingo.',
                    'tipo' => Promotion::PORCENTAJE, 'pct' => 0.15, 'lleva' => null, 'paga' => null,
                    'productos' => $buscar(['Cazuela de mariscos', 'Mojarra frita']),
                    'desde' => $hoy->copy()->subDays(4), 'hasta' => $domingo, 'max' => null, 'estado' => Promotion::ENVIADA,
                ],
                [
                    'texto' => 'Domicilio gratis en almuerzos pedidos antes de las 11 a. m.',
                    'tipo' => Promotion::SIN_DESCUENTO, 'pct' => null, 'lleva' => null, 'paga' => null,
                    'productos' => [],
                    'desde' => $hoy->copy()->addDay(), 'hasta' => $hoy->copy()->addDays(8)->endOfDay(), 'max' => null,
                    'estado' => Promotion::BORRADOR,
                ],
            ],
        };

        $afiliados = DB::table('business_user_affiliations')->where('busines_id', $negocio)->count();
        $salida = [];

        foreach ($defs as $d) {
            $enviada = $d['estado'] === Promotion::ENVIADA;

            $id = (int) DB::table('promotions')->insertGetId([
                'busines_id' => $negocio,
                'description' => $d['texto'],
                'tipo' => $d['tipo'],
                'percentage_discount' => $d['pct'],
                'lleva' => $d['lleva'],
                'paga' => $d['paga'],
                'start_date' => $d['desde'],
                'end_date' => $d['hasta'],
                'max_usos' => $d['max'],
                'usos' => 0,
                'sent_at' => $enviada ? $d['desde']->copy()->setTime(8, 15) : null,
                'recipients_count' => $enviada ? $afiliados : 0,
                'created_by' => $dueno,
                'state' => $d['estado'],
                'created_at' => $d['desde']->copy()->subDay()->setTime(19, 40),
                'updated_at' => $d['desde']->copy()->setTime(8, 15),
            ], 'promotion_id');

            $this->libreta['promociones'][] = $id;

            foreach ($d['productos'] as $pid) {
                DB::table('promotion_products')->insert(['promotion_id' => $id, 'products_id' => $pid]);
            }

            if ($d['tipo'] !== Promotion::SIN_DESCUENTO && $enviada && $d['productos'] !== []) {
                $salida[] = [
                    'id' => $id, 'tipo' => $d['tipo'], 'pct' => $d['pct'], 'lleva' => $d['lleva'], 'paga' => $d['paga'],
                    'productos' => $d['productos'], 'desde' => $d['desde'], 'hasta' => $d['hasta'],
                    'max' => $d['max'], 'usos' => 0,
                ];
            }
        }

        return $salida;
    }

    /** Lo que cuesta tener el local abierto, con lo que cobra cada mes. */
    private function gastos(int $negocio, string $oficio): void
    {
        $inicio = $this->ahora->copy()->subMonthsNoOverflow(3)->startOfMonth();

        /* [tipo, nombre, monto, frecuencia, desde, montos por mes (desde hace N meses => monto)] */
        $defs = match ($oficio) {
            self::TIENDA => [
                ['agua', 'Agua', 48000, 'mensual', $inicio, [2 => 45000, 1 => 52000]],
                ['internet', 'Internet y celular', 75000, 'mensual', $inicio, []],
                ['empleados', 'Ayudante de mostrador (medio tiempo)', 750000, 'mensual', $inicio, []],
                ['transporte', 'Flete del surtido semanal', 120000, 'mensual', $inicio, []],
                ['otro', 'Reparación de la nevera de las gaseosas', 180000, 'una_vez', $this->ahora->copy()->subDays(12)->startOfDay(), []],
            ],
            self::DROGUERIA => [
                ['arriendo', 'Arriendo del local', 1800000, 'mensual', $inicio, []],
                ['luz', 'Luz (con el aire y la nevera)', 230000, 'mensual', $inicio, [2 => 215000, 1 => 248000]],
                ['agua', 'Agua', 55000, 'mensual', $inicio, []],
                ['internet', 'Internet y datáfono', 95000, 'mensual', $inicio, []],
                ['empleados', 'Auxiliar de farmacia', 1650000, 'mensual', $inicio, []],
                ['otro', 'Programa de inventario y facturación', 89000, 'mensual', $inicio, []],
                ['otro', 'Nevera para medicamentos refrigerados', 950000, 'una_vez', $this->ahora->copy()->subDays(41)->startOfDay(), []],
            ],
            default => [
                ['arriendo', 'Arriendo del local', 1500000, 'mensual', $inicio, []],
                ['luz', 'Luz', 360000, 'mensual', $inicio, [2 => 338000, 1 => 395000]],
                ['agua', 'Agua', 125000, 'mensual', $inicio, []],
                ['gas', 'Gas de la cocina', 290000, 'mensual', $inicio, [1 => 310000]],
                ['internet', 'Internet y celular', 80000, 'mensual', $inicio, []],
                ['empleados', 'Cocinera', 1550000, 'mensual', $inicio, []],
                ['empleados', 'Auxiliar de cocina y empaque', 1300000, 'mensual', $inicio, []],
                ['otro', 'Empaques y cubiertos desechables', 210000, 'mensual', $inicio, []],
                ['otro', 'Mantenimiento de la estufa industrial', 260000, 'una_vez', $this->ahora->copy()->subDays(23)->startOfDay(), []],
            ],
        };

        foreach ($defs as [$tipo, $nombre, $monto, $frecuencia, $desde, $porMes]) {
            $id = (int) DB::table('gastos_del_negocio')->insertGetId([
                'busines_id' => $negocio,
                'tipo' => $tipo,
                'nombre' => mb_substr($nombre, 0, 60),
                'monto' => $monto,
                'frecuencia' => $frecuencia,
                'desde' => $desde->toDateString(),
                'hasta' => null,
                'created_at' => $desde,
                'updated_at' => $desde,
            ], 'gasto_id');

            $this->libreta['gastos'][] = $id;

            foreach ($porMes as $mesesAtras => $real) {
                DB::table('gastos_del_mes')->insert([
                    'gasto_id' => $id,
                    'periodo' => $this->ahora->copy()->subMonthsNoOverflow($mesesAtras)->format('Y-m'),
                    'monto' => $real,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /* ================================================================== */
    /* LA HISTORIA: dos meses de pedidos */

    private function historia(
        int $negocio,
        string $oficio,
        int $dueno,
        array $flota,
        array $surtido,
        array $clientes,
        array &$promos,
    ): void {
        /* Lo que cada domiciliario lleva encima de ESTE negocio y de esta semilla. */
        $enLaCalle = array_fill_keys($flota, 0.0);
        $hoy = $this->ahora->copy()->startOfDay();

        for ($d = self::DIAS; $d >= 0; $d--) {
            $dia = $hoy->copy()->subDays($d);
            $horas = $this->horasDelDia($oficio, $dia, $d, $this->cuantosPedidos($oficio, $dia, $d));

            foreach ($horas as $cuando) {
                $cancelado = mt_rand(1, 100) <= 6;
                $pedido = $this->unPedidoPasado($negocio, $oficio, $flota, $surtido, $clientes, $promos, $cuando, $cancelado);

                if ($pedido && $pedido['efectivo_con'] !== null) {
                    $enLaCalle[$pedido['efectivo_con']] += $pedido['total'];
                }
            }

            $this->cierreDelDia($negocio, $dueno, $dia, $d, $enLaCalle);
            $this->abonosDelFiado($negocio, $dueno, $dia, $d);
        }
    }

    /** Cuántos pedidos ese día: más el fin de semana y en quincena, y el negocio crece. */
    private function cuantosPedidos(string $oficio, Carbon $dia, int $diasAtras): int
    {
        [$min, $max] = match ($oficio) {
            self::TIENDA => [4, 8],
            self::DROGUERIA => [4, 7],
            default => [5, 9],
        };

        $n = mt_rand($min, $max);

        if ($dia->isSaturday() || $dia->isSunday()) {
            $n += mt_rand(2, 4);
        }

        if (in_array($dia->day, [15, 16, 30, 31, 1], true)) {
            $n += mt_rand(1, 3);
        }

        /* Dos meses atrás el negocio vendía un poco menos. */
        if ($diasAtras > 40) {
            $n -= 1;
        }

        return max(3, $n);
    }

    /**
     * A qué horas llegan los pedidos de ese oficio. Hoy, sólo los que ya
     * pasaron: un pedido entregado en el futuro sería un dato mintiendo.
     *
     * @return list<Carbon>
     */
    private function horasDelDia(string $oficio, Carbon $dia, int $diasAtras, int $n): array
    {
        $perfil = match ($oficio) {
            self::TIENDA => [7 => 6, 8 => 8, 9 => 7, 10 => 6, 11 => 6, 12 => 7, 13 => 5, 14 => 4, 15 => 4, 16 => 5, 17 => 7, 18 => 9, 19 => 10, 20 => 8, 21 => 5],
            self::DROGUERIA => [8 => 5, 9 => 7, 10 => 7, 11 => 6, 12 => 5, 13 => 4, 14 => 4, 15 => 5, 16 => 5, 17 => 6, 18 => 7, 19 => 8, 20 => 7, 21 => 5, 22 => 3],
            default => [7 => 3, 8 => 4, 9 => 3, 10 => 2, 11 => 8, 12 => 14, 13 => 12, 14 => 6, 15 => 2, 16 => 2, 17 => 3, 18 => 6, 19 => 9, 20 => 8, 21 => 4],
        };

        $horas = [];

        for ($i = 0; $i < $n; $i++) {
            $h = $this->elegir($perfil);
            $horas[] = $dia->copy()->setTime($h, mt_rand(0, 59), mt_rand(0, 59));
        }

        if ($diasAtras === 0) {
            $limite = $this->ahora->copy()->subMinutes(50);
            $horas = array_values(array_filter($horas, fn (Carbon $c) => $c->lt($limite)));

            /*
             * Corrido de madrugada, el día tiene pocas horas: un par de
             * pedidos trasnochados para que «Hoy» no abra en cero.
             */
            if (count($horas) < 2 && $limite->gt($dia->copy()->addMinutes(20))) {
                $ventana = (int) $dia->copy()->addMinutes(10)->diffInMinutes($limite);
                for ($i = count($horas); $i < 2; $i++) {
                    $horas[] = $dia->copy()->addMinutes(10 + mt_rand(0, max(0, $ventana)));
                }
            }
        }

        usort($horas, fn (Carbon $a, Carbon $b) => $a <=> $b);

        return $horas;
    }

    /**
     * Un pedido ya resuelto: entregado o cancelado.
     *
     * @return array{id:int, total:float, efectivo_con:?int}|null
     */
    private function unPedidoPasado(
        int $negocio,
        string $oficio,
        array $flota,
        array $surtido,
        array $clientes,
        array &$promos,
        Carbon $cuando,
        bool $cancelado,
    ): ?array {
        $clave = $this->elegir($clientes);
        $vecino = $this->vecinos[$clave];

        $recoge = mt_rand(1, 100) <= match ($oficio) {
            self::RESTAURANTE => 12,
            self::TIENDA => 5,
            default => 3,
        };

        $metodo = $this->elegir([
            self::EFECTIVO => 50, self::NEQUI => 22, self::TARJETA => 10,
            self::QR => 10, self::TRANSFERENCIA => 8,
        ]);

        /* El que tiene cupo, casi la mitad de las veces pide fiado. */
        if (! $cancelado && mt_rand(1, 100) <= 45 && $this->tieneCupo($negocio, $vecino['user_id'])) {
            $metodo = self::FIADO;
        }

        $ninguna = [];
        [$renglones, $subtotal, $descuento, $promoAplicada] = $cancelado
            ? $this->armar($oficio, $surtido, $cuando, $ninguna)
            : $this->armar($oficio, $surtido, $cuando, $promos);

        $domicilio = $recoge ? 0.0 : (float) [4000, 5000, 5000, 6000, 7000][mt_rand(0, 4)];
        $total = $subtotal - $descuento + $domicilio;

        if ($metodo === self::FIADO && ! $this->alcanzaElCupo($negocio, $vecino['user_id'], $total)) {
            $metodo = self::EFECTIVO;
        }

        $domi = (! $recoge && $flota !== []) ? $flota[mt_rand(0, count($flota) - 1)] : null;
        $preparacion = match ($oficio) {
            self::RESTAURANTE => mt_rand(14, 32),
            self::TIENDA => mt_rand(6, 16),
            default => mt_rand(5, 12),
        };
        $despacho = $cuando->copy()->addMinutes($preparacion);
        $entrega = $despacho->copy()->addMinutes($recoge ? mt_rand(5, 25) : mt_rand(8, 24));

        if ($entrega->gt($this->ahora)) {
            $entrega = $this->ahora->copy()->subMinutes(2);
        }

        $enEfectivo = $metodo === self::EFECTIVO;
        $paga = $enEfectivo ? $this->conCuantoPaga($total) : null;
        $estado = $cancelado ? OrdersSales::CANCELADO : OrdersSales::ENTREGADO;

        $id = (int) DB::table('orderssales')->insertGetId([
            'buyer_id' => $vecino['buyer_id'],
            'busines_id' => $negocio,
            'domiciliary_id' => $cancelado ? null : $domi,
            'address_id' => $recoge ? null : $vecino['address_id'],
            'methods_id' => $metodo,
            'forms_id' => in_array($metodo, [self::EFECTIVO, self::FIADO], true) ? self::CONTRA_ENTREGA : self::ANTICIPADO,
            'subtotal' => $subtotal,
            'domicilio' => $domicilio,
            'discount' => $descuento,
            'domiciliary_fee' => $recoge ? 0 : round($domicilio * $this->reparto),
            'platform_fee' => round($subtotal * $this->comision, 2),
            'delivery_subsidy' => 0,
            'total' => $total,
            'cash_due' => 0,
            'cash_tendered' => $paga,
            'cash_received' => $enEfectivo && ! $cancelado ? $paga : null,
            'change_given' => $enEfectivo && ! $cancelado ? $paga - $total : null,
            'weighing_state' => 0,
            'currency' => 'COP',
            'sale_date' => $cuando,
            'state' => $estado,
            'has_review' => 0,
            'payment_state' => $this->estadoDelPago($metodo, $estado),
            'pickup' => $recoge ? 1 : 0,
            'pin_entrega' => $recoge ? PinDeEntrega::generar() : null,
            'is_scheduled' => 0,
            'stock_descontado' => $cancelado ? 0 : 1,
            'ready_at' => $cancelado ? null : $despacho->copy()->subMinute(),
            'dispatched_at' => $cancelado ? null : $despacho,
            'delivery_date' => $cancelado ? null : $entrega,
            'promised_minutes' => $oficio === self::RESTAURANTE ? 45 : 30,
            'created_at' => $cuando,
            'updated_at' => $cancelado ? $cuando->copy()->addMinutes(mt_rand(4, 15)) : $entrega,
        ], 'orderSales_id');

        $this->libreta['pedidos'][] = $id;
        $this->renglones($id, $renglones);

        if ($promoAplicada !== null) {
            DB::table('orders_promotions')->insert(['orderSales_id' => $id, 'promotion_id' => $promoAplicada, 'state' => 1]);
        }

        if ($cancelado) {
            return ['id' => $id, 'total' => $total, 'efectivo_con' => null];
        }

        if ($metodo === self::FIADO && ! $this->consumirFiado($id, $vecino['user_id'], $cuando)) {
            $metodo = self::EFECTIVO;
        }

        $this->alEntregar($id, $metodo, $entrega);

        return [
            'id' => $id,
            'total' => $total,
            'efectivo_con' => $enEfectivo && $domi ? $domi : null,
        ];
    }

    /**
     * Lo que pasa al entregar, por el camino de producción: el cobro contra
     * entrega apunta el pago, marca pagado y registra el recaudo del
     * domiciliario. Después se le pone la hora de la entrega, que el servicio
     * no puede saber porque corre «ahora».
     */
    private function alEntregar(int $id, int $metodo, Carbon $entrega): void
    {
        if (in_array($metodo, [self::EFECTIVO, self::FIADO], true)) {
            $this->cobro->registrar(OrdersSales::findOrFail($id));
        } else {
            $o = DB::table('orderssales')->where('orderSales_id', $id)->first();

            DB::table('payments')->insert([
                'orderSales_id' => $id,
                'methods_id' => $metodo,
                'forms_id' => self::ANTICIPADO,
                'provider' => 'bold',
                'provider_payment_id' => 'muestra-'.$id,
                'amount' => $o->total,
                'subtotal' => $o->subtotal,
                'total' => $o->total,
                'domicilio' => $o->domicilio,
                'domiciliary_fee' => $o->domiciliary_fee,
                'valor_promocion' => $o->discount,
                'payment_status' => 1,
                'status' => 'approved',
                'payment_date' => $o->sale_date,
                'state' => 1,
                'created_at' => $o->sale_date,
                'updated_at' => $o->sale_date,
            ]);
        }

        if (in_array($metodo, [self::EFECTIVO, self::FIADO], true)) {
            DB::table('payments')->where('orderSales_id', $id)
                ->update(['payment_date' => $entrega, 'created_at' => $entrega, 'updated_at' => $entrega]);
        }

        DB::table('cash_movements')->where('order_id', $id)->update(['created_at' => $entrega, 'updated_at' => $entrega]);
        DB::table('orderssales')->where('orderSales_id', $id)->update(['updated_at' => $entrega]);
    }

    private function estadoDelPago(int $metodo, int $estado): string
    {
        if ($estado === OrdersSales::CANCELADO) {
            return 'cancelled';
        }

        if ($estado === OrdersSales::ENTREGADO) {
            /* Lo deja así `CobroContraEntrega`; los de pasarela ya venían pagados. */
            return match ($metodo) {
                self::EFECTIVO => 'pending_cash',
                self::FIADO => OrdersSales::A_CREDITO,
                default => 'paid',
            };
        }

        return match ($metodo) {
            self::EFECTIVO => 'pending_cash',
            self::FIADO => OrdersSales::A_CREDITO,
            default => 'paid',
        };
    }

    /**
     * Los renglones del pedido, con la promoción si le toca.
     *
     * @return array{0: list<array>, 1: float, 2: float, 3: ?int}
     */
    private function armar(string $oficio, array $surtido, Carbon $cuando, array &$promos): array
    {
        $porId = [];

        $agregar = function (array $p, int $cantidad) use (&$porId) {
            if (isset($porId[$p['id']])) {
                $porId[$p['id']]['cantidad'] += $cantidad;
            } else {
                $porId[$p['id']] = ['p' => $p, 'cantidad' => $cantidad];
            }
        };

        if ($oficio === self::RESTAURANTE) {
            $momento = $cuando->hour < 10 ? 'desayuno' : ($cuando->hour < 16 ? 'almuerzo' : 'cena');
            $personas = $this->elegir([1 => 48, 2 => 30, 3 => 12, 4 => 10]);

            for ($i = 0; $i < $personas; $i++) {
                $plato = $this->delGrupo($surtido, $momento) ?? $this->delGrupo($surtido, 'almuerzo');
                if ($plato) {
                    $agregar($plato, 1);
                }
                if (mt_rand(1, 100) <= 70 && ($b = $this->delGrupo($surtido, 'bebida'))) {
                    $agregar($b, 1);
                }
                if (mt_rand(1, 100) <= 14 && ($p = $this->delGrupo($surtido, 'postre'))) {
                    $agregar($p, 1);
                }
                if (mt_rand(1, 100) <= 12 && ($a = $this->delGrupo($surtido, 'adicional'))) {
                    $agregar($a, 1);
                }
            }
        } else {
            $cuantos = $oficio === self::TIENDA
                ? $this->elegir([1 => 12, 2 => 22, 3 => 25, 4 => 18, 5 => 13, 6 => 10])
                : $this->elegir([1 => 45, 2 => 33, 3 => 16, 4 => 6]);

            $pesos = array_map(fn ($p) => $p['peso'], $surtido);

            for ($i = 0; $i < $cuantos; $i++) {
                $p = $surtido[$this->elegir($pesos)];
                $cantidad = $oficio === self::TIENDA
                    ? $this->elegir([1 => 60, 2 => 28, 3 => 12])
                    : $this->elegir([1 => 76, 2 => 21, 3 => 3]);
                $agregar($p, $cantidad);
            }
        }

        /* La promoción vigente: a veces el vecino la aprovecha. */
        $descuento = 0.0;
        $aplicada = null;

        foreach ($promos as &$promo) {
            if (! $cuando->between($promo['desde'], $promo['hasta'])) {
                continue;
            }
            if ($promo['max'] !== null && $promo['usos'] >= $promo['max']) {
                continue;
            }
            if (mt_rand(1, 100) > 35) {
                continue;
            }

            $pid = $promo['productos'][mt_rand(0, count($promo['productos']) - 1)];
            $p = null;
            foreach ($surtido as $s) {
                if ($s['id'] === $pid) {
                    $p = $s;
                    break;
                }
            }
            if (! $p) {
                continue;
            }

            if ($promo['tipo'] === Promotion::NXM) {
                $agregar($p, 0);
                $porId[$pid]['cantidad'] = max($porId[$pid]['cantidad'], (int) $promo['lleva']);
                $gratis = intdiv($porId[$pid]['cantidad'], (int) $promo['lleva']) * ((int) $promo['lleva'] - (int) $promo['paga']);
                $descuento = $gratis * $p['precio'];
            } else {
                $agregar($p, isset($porId[$pid]) ? 0 : 1);
                $descuento = round($p['precio'] * $porId[$pid]['cantidad'] * (float) $promo['pct']);
            }

            $promo['usos']++;
            $aplicada = $promo['id'];
            break;
        }
        unset($promo);

        $renglones = [];
        $subtotal = 0.0;

        foreach ($porId as $r) {
            $linea = $r['p']['precio'] * $r['cantidad'];
            $subtotal += $linea;
            $renglones[] = [
                'product_id' => $r['p']['id'],
                'amount' => $r['cantidad'],
                'unit_price' => $r['p']['precio'],
                'sale_unit' => 'unidad',
                'line_total' => $linea,
                /* Congelado al vender, como lo hace el armado del pedido. */
                'unit_cost' => $r['p']['costo'],
            ];
        }

        return [$renglones, $subtotal, (float) $descuento, $aplicada];
    }

    private function delGrupo(array $surtido, string $grupo): ?array
    {
        $pesos = [];
        foreach ($surtido as $i => $p) {
            if ($p['grupo'] === $grupo) {
                $pesos[$i] = $p['peso'];
            }
        }

        return $pesos === [] ? null : $surtido[$this->elegir($pesos)];
    }

    private function renglones(int $pedido, array $renglones): void
    {
        foreach ($renglones as $r) {
            DB::table('orderssales_detail')->insert($r + ['orderSales_id' => $pedido]);
        }
    }

    private function conCuantoPaga(float $total): float
    {
        $tiro = mt_rand(1, 100);

        if ($tiro <= 30) {
            return $total;
        }

        if ($tiro <= 70 || $total > 50000) {
            return ceil($total / 10000) * 10000;
        }

        return $total <= 50000 ? 50000.0 : 100000.0;
    }

    /* ------------------------------------------------------------------ */
    /* El fiado */

    private function tieneCupo(int $negocio, int $userId): bool
    {
        return DB::table('store_credits')->where('busines_id', $negocio)->where('user_id', $userId)
            ->where('credit_limit', '>', 0)->exists();
    }

    private function alcanzaElCupo(int $negocio, int $userId, float $total): bool
    {
        $cupo = $this->credito->cupo($negocio, $userId);

        return $cupo['disponible'] >= $total;
    }

    /** El consumo va por el servicio, que es el que valida cupo y tope. */
    private function consumirFiado(int $pedido, int $userId, Carbon $cuando): bool
    {
        try {
            $mov = $this->credito->consumir(OrdersSales::findOrFail($pedido), $userId);
            DB::table('store_credit_movements')->where('id', $mov->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

            return true;
        } catch (Throwable) {
            /* Si el servicio dice que no, el pedido se pagó en efectivo. */
            DB::table('orderssales')->where('orderSales_id', $pedido)->update([
                'methods_id' => self::EFECTIVO,
                'payment_state' => 'pending_cash',
            ]);

            return false;
        }
    }

    /**
     * Los abonos: el grueso en quincena (15 y 30), como paga la gente que
     * vive del sueldo. Desde la última quincena NO se abona, para que hoy
     * haya quien deba.
     */
    private function abonosDelFiado(int $negocio, int $dueno, Carbon $dia, int $diasAtras): void
    {
        $quincena = in_array($dia->day, [15, 30], true) || ($dia->month === 2 && $dia->isLastOfMonth());

        if (! $quincena) {
            return;
        }

        $creditos = DB::table('store_credits')->where('busines_id', $negocio)->orderBy('id')->get(['id', 'user_id']);

        foreach ($creditos as $i => $c) {
            $usado = $this->credito->usado((int) $c->id);

            if ($usado <= 0) {
                continue;
            }

            $monto = $i % 3 !== 2 ? $usado : floor($usado * 0.5 / 1000) * 1000;

            if ($monto <= 0) {
                continue;
            }

            try {
                $mov = $this->credito->abonar($negocio, (int) $c->user_id, (float) $monto, $dueno, 'Abono de la quincena');
            } catch (Throwable) {
                continue;
            }

            $cuando = $dia->copy()->setTime(18, mt_rand(0, 59));
            DB::table('store_credit_movements')->where('id', $mov->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);
            $this->libreta['abonos'][] = (int) $mov->id;
        }
    }

    /**
     * Y uno que ayer pagó todo: la insignia de «al día» al lado de los que
     * deben también es pantalla. Es el primer cupo del negocio, que no es
     * ninguno de los que hoy tienen un pedido fiado en curso.
     */
    private function clienteAlDia(int $negocio, int $dueno): void
    {
        $c = DB::table('store_credits')->where('busines_id', $negocio)->orderBy('id')->first(['id', 'user_id']);
        $usado = $c ? $this->credito->usado((int) $c->id) : 0.0;

        if (! $c || $usado <= 0) {
            return;
        }

        $mov = $this->credito->abonar($negocio, (int) $c->user_id, $usado, $dueno, 'Pagó todo lo que debía');
        $cuando = $this->ahora->copy()->subDay()->setTime(19, 30);
        DB::table('store_credit_movements')->where('id', $mov->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);
        $this->libreta['abonos'][] = (int) $mov->id;
    }

    /* ------------------------------------------------------------------ */
    /* El efectivo */

    /**
     * Al cerrar, cada domiciliario entrega en el mostrador lo que cobró. Por
     * el servicio de verdad, que es el que pone el tope de lo cobrado en
     * pedidos de esta tienda. Algunos días sólo abonan una parte; ayer y hoy
     * casi nadie ha entregado: es la plata que sigue en la calle.
     */
    private function cierreDelDia(int $negocio, int $dueno, Carbon $dia, int $diasAtras, array &$enLaCalle): void
    {
        if ($diasAtras === 0) {
            return;
        }

        foreach ($enLaCalle as $domi => $debe) {
            if ($debe <= 0) {
                continue;
            }

            /* Ayer sólo entregó el primero de la flota: el que queda «al día». */
            if ($diasAtras === 1 && $domi !== array_key_first($enLaCalle)) {
                continue;
            }

            $parcial = $diasAtras > 1 && mt_rand(1, 100) <= 18;
            $quiere = $parcial ? floor($debe * 0.6 / 1000) * 1000 : $debe;
            $consignacion = $diasAtras === 6 && $domi === array_key_last($enLaCalle);

            $tope = min(
                $this->custodia->pendienteConLaTienda($negocio, (int) $domi),
                max(0.0, $this->custodia->saldo((int) $domi)),
            );
            $monto = round(min($quiere, $tope), 2);

            if ($monto <= 0) {
                continue;
            }

            $cuando = $dia->copy()->setTime(21, mt_rand(10, 55));

            try {
                if ($consignacion) {
                    $dep = $this->custodia->declararDeposito((int) $domi, [
                        'amount' => $monto,
                        'reference' => 'Consignación Bancolombia '.mt_rand(1000, 9999),
                        'deposited_at' => $cuando,
                    ]);
                    $dep->update(['canal' => CashDeposit::CONSIGNACION, 'busines_id' => $negocio]);
                    $dep = $this->custodia->confirmarDeposito($dep, $dueno, 'Consignó en el banco');
                } else {
                    $dep = $this->custodia->recibirEnMostrador(
                        $negocio,
                        (int) $domi,
                        (float) $monto,
                        $dueno,
                        $parcial ? 'Abono, se quedó con un billete grande' : 'Entregó todo al cerrar',
                        $this->comoUuid("muestra-{$negocio}-{$domi}-{$dia->toDateString()}"),
                    );
                }
            } catch (Throwable $e) {
                $this->command?->warn("  Entrega de {$domi} el {$dia->toDateString()}: {$e->getMessage()}");

                continue;
            }

            DB::table('cash_deposits')->where('id', $dep->id)->update([
                'deposited_at' => $cuando, 'confirmed_at' => $cuando,
                'created_at' => $cuando, 'updated_at' => $cuando,
            ]);
            DB::table('cash_movements')->where('deposit_id', $dep->id)
                ->where('type', CashMovement::CONSIGNACION)
                ->update(['created_at' => $cuando, 'updated_at' => $cuando]);

            $this->libreta['depositos'][] = (int) $dep->id;
            $enLaCalle[$domi] = max(0.0, $debe - $monto);
        }
    }

    /* ================================================================== */
    /* HOY: lo que está pasando ahora */

    /**
     * Uno en cada momento del día del tendero. Las edades están puestas para
     * que uno pase de los treinta minutos sin aceptar —el que se pone en
     * rojo— y los que van en camino tengan rastro en el mapa.
     */
    private function enCursoHoy(int $negocio, string $oficio, array $flota, array $surtido, array $clientes): void
    {
        /* [estado, minutos desde que entró, medio, recoge] */
        $plan = [
            [OrdersSales::NUEVO, 3, self::EFECTIVO, false],
            [OrdersSales::NUEVO, 37, self::NEQUI, false],
            [OrdersSales::PREPARANDO, 14, self::EFECTIVO, false],
            [OrdersSales::PREPARANDO, 24, self::FIADO, false],
            [OrdersSales::EN_CAMINO, 29, self::EFECTIVO, false],
            [OrdersSales::EN_CAMINO, 42, self::TARJETA, false],
        ];

        if ($oficio === self::RESTAURANTE) {
            $plan[] = [OrdersSales::PREPARANDO, 11, self::EFECTIVO, true];
            $plan[] = [OrdersSales::NUEVO, 6, self::QR, false];
        }

        $negocioGeo = DB::table('business')->where('busines_id', $negocio)->first(['latitude', 'longitude']);
        $sinPromo = [];
        $fiados = array_keys(self::FIADOS[$negocio]);
        $enCamino = 0;

        foreach ($plan as $k => [$estado, $hace, $metodo, $recoge]) {
            $clave = $metodo === self::FIADO ? $fiados[$k % count($fiados)] : $this->elegir($clientes);
            $vecino = $this->vecinos[$clave] ?? null;

            if (! $vecino) {
                continue;
            }

            $cuando = $this->ahora->copy()->subMinutes($hace);
            [$renglones, $subtotal] = $this->armar($oficio, $surtido, $cuando, $sinPromo);
            $domicilio = $recoge ? 0.0 : 5000.0;
            $total = $subtotal + $domicilio;

            if ($metodo === self::FIADO && ! $this->alcanzaElCupo($negocio, $vecino['user_id'], $total)) {
                $metodo = self::EFECTIVO;
            }

            $domi = null;
            if ($estado === OrdersSales::EN_CAMINO && $flota !== []) {
                $domi = $flota[$enCamino % count($flota)];
                $enCamino++;
            }

            $despacho = $estado === OrdersSales::EN_CAMINO ? $cuando->copy()->addMinutes((int) round($hace * 0.55)) : null;

            $id = (int) DB::table('orderssales')->insertGetId([
                'buyer_id' => $vecino['buyer_id'],
                'busines_id' => $negocio,
                'domiciliary_id' => $domi,
                'address_id' => $recoge ? null : $vecino['address_id'],
                'methods_id' => $metodo,
                'forms_id' => in_array($metodo, [self::EFECTIVO, self::FIADO], true) ? self::CONTRA_ENTREGA : self::ANTICIPADO,
                'subtotal' => $subtotal,
                'domicilio' => $domicilio,
                'discount' => 0,
                'domiciliary_fee' => round($domicilio * $this->reparto),
                'platform_fee' => round($subtotal * $this->comision, 2),
                'delivery_subsidy' => 0,
                'total' => $total,
                'cash_due' => 0,
                'cash_tendered' => $metodo === self::EFECTIVO ? $this->conCuantoPaga($total) : null,
                'weighing_state' => 0,
                'currency' => 'COP',
                'sale_date' => $cuando,
                'state' => $estado,
                'has_review' => 0,
                'payment_state' => $this->estadoDelPago($metodo, $estado),
                'pickup' => $recoge ? 1 : 0,
                'pin_entrega' => $recoge ? PinDeEntrega::generar() : null,
                'is_scheduled' => 0,
                'stock_descontado' => $estado === OrdersSales::NUEVO ? 0 : 1,
                'ready_at' => $despacho?->copy()->subMinute(),
                'dispatched_at' => $despacho,
                'promised_minutes' => $oficio === self::RESTAURANTE ? 45 : 30,
                'created_at' => $cuando,
                'updated_at' => $despacho ?? $cuando,
            ], 'orderSales_id');

            $this->libreta['pedidos'][] = $id;
            $this->renglones($id, $renglones);

            if ($metodo === self::FIADO && ! $this->consumirFiado($id, $vecino['user_id'], $cuando)) {
                $metodo = self::EFECTIVO;
            }

            if (! in_array($metodo, [self::EFECTIVO, self::FIADO], true)) {
                DB::table('payments')->insert([
                    'orderSales_id' => $id, 'methods_id' => $metodo, 'forms_id' => self::ANTICIPADO,
                    'provider' => 'bold', 'provider_payment_id' => 'muestra-'.$id,
                    'amount' => $total, 'subtotal' => $subtotal, 'total' => $total,
                    'domicilio' => $domicilio, 'domiciliary_fee' => round($domicilio * $this->reparto),
                    'valor_promocion' => 0, 'payment_status' => 1, 'status' => 'approved',
                    'payment_date' => $cuando, 'state' => 1, 'created_at' => $cuando, 'updated_at' => $cuando,
                ]);
            }

            if ($domi && $despacho && $negocioGeo) {
                $this->rastro($id, $domi, $despacho, (float) $negocioGeo->latitude, (float) $negocioGeo->longitude, $vecino);
            }
        }
    }

    /**
     * El rastro de la moto, del negocio hacia la casa del cliente, un punto
     * cada dos minutos desde que salió. El último, de hace un minuto: la
     * torre lo pinta «en vivo».
     */
    private function rastro(int $pedido, int $domi, Carbon $salio, float $lat0, float $lng0, array $vecino): void
    {
        $minutos = max(2, (int) $salio->diffInMinutes($this->ahora));
        $puntos = max(2, intdiv($minutos, 2));

        for ($i = 1; $i <= $puntos; $i++) {
            $avance = min(0.85, ($i / $puntos) * 0.85);
            $cuando = $i === $puntos ? $this->ahora->copy()->subMinute() : $salio->copy()->addMinutes($i * 2);

            $id = (int) DB::table('order_geolocation')->insertGetId([
                'domiciliary_id' => $domi,
                'orderSales_id' => $pedido,
                'latitude' => round($lat0 + ($vecino['lat'] - $lat0) * $avance + mt_rand(-6, 6) / 100000, 6),
                'longitude' => round($lng0 + ($vecino['lng'] - $lng0) * $avance + mt_rand(-6, 6) / 100000, 6),
                'state' => 1,
                'created_at' => $cuando,
                'updated_at' => $cuando,
            ], 'geolocation_id');

            $this->libreta['posiciones'][] = $id;
        }
    }

    /* ================================================================== */
    /* EL MOSTRADOR */

    /** Lo que se vende en el mostrador, sin pedido: menudeo del día. */
    private function mostrador(int $negocio, string $oficio, int $dueno, array $surtido): void
    {
        $hoy = $this->ahora->copy()->startOfDay();
        $pesos = array_map(fn ($p) => $p['peso'], $surtido);

        for ($d = self::DIAS; $d >= 0; $d--) {
            $dia = $hoy->copy()->subDays($d);
            $n = match ($oficio) {
                self::TIENDA => mt_rand(7, 13),
                self::DROGUERIA => mt_rand(4, 8),
                default => mt_rand(6, 12),
            };
            if ($dia->isSaturday() || $dia->isSunday()) {
                $n += mt_rand(1, 3);
            }

            foreach ($this->horasDelDia($oficio, $dia, $d, $n) as $cuando) {
                $lineas = [];

                if ($oficio === self::RESTAURANTE) {
                    $momento = $cuando->hour < 10 ? 'desayuno' : ($cuando->hour < 16 ? 'almuerzo' : 'cena');
                    $elegidos = array_filter([$this->delGrupo($surtido, $momento), mt_rand(1, 100) <= 60 ? $this->delGrupo($surtido, 'bebida') : null]);
                } else {
                    $cuantos = $oficio === self::TIENDA ? mt_rand(1, 4) : mt_rand(1, 2);
                    $elegidos = [];
                    for ($i = 0; $i < $cuantos; $i++) {
                        $p = $surtido[$this->elegir($pesos)];
                        $elegidos[$p['id']] = $p;
                    }
                }

                $total = 0.0;
                foreach ($elegidos as $p) {
                    $cantidad = $oficio === self::TIENDA ? $this->elegir([1 => 70, 2 => 25, 3 => 5]) : 1;
                    $linea = $p['precio'] * $cantidad;
                    $total += $linea;
                    $lineas[] = [
                        'products_id' => $p['id'], 'nombre' => $p['nombre'], 'sale_unit' => 'unidad',
                        'pedido_por' => null, 'cantidad' => $cantidad, 'unit_price' => $p['precio'],
                        'unit_cost' => $p['costo'], 'line_total' => $linea,
                    ];
                }

                if ($lineas === []) {
                    continue;
                }

                $forma = $this->elegir(['efectivo' => 66, 'transferencia' => 16, 'qr' => 18]);
                $recibido = $forma === 'efectivo' && mt_rand(1, 100) <= 55 ? $this->conCuantoPaga($total) : null;

                $venta = (int) DB::table('ventas_mostrador')->insertGetId([
                    'busines_id' => $negocio,
                    'user_id' => $dueno,
                    'total' => $total,
                    'forma_de_pago' => $forma,
                    'estado_pago' => 'pagada',
                    'comision_pasarela' => $forma === 'qr' ? round($total * (float) config('operacion.comision_qr', 0.0289), 2) : 0,
                    'cash_tendered' => $recibido,
                    'change_given' => $recibido !== null ? round($recibido - $total, 2) : null,
                    'vendida_at' => $cuando,
                    'created_at' => $cuando,
                    'updated_at' => $cuando,
                ], 'venta_id');

                $this->libreta['ventas'][] = $venta;

                foreach ($lineas as $l) {
                    DB::table('ventas_mostrador_lineas')->insert($l + ['venta_id' => $venta]);
                }
            }
        }
    }

    /* ================================================================== */
    /* LAS RESEÑAS */

    private function resenas(int $negocio, string $oficio): void
    {
        $textos = match ($oficio) {
            self::TIENDA => [
                5 => ['Llegó rapidísimo y completo.', 'Siempre tienen de todo, hasta lo que se me olvida comprar.', 'Entrega rápida',
                    'El domiciliario muy amable.', 'Los huevos llegaron enteritos, bien empacados.', 'Buenos precios'],
                4 => ['Buen servicio, solo que la gaseosa no venía fría.', 'Todo bien, se demoró un poquito por la lluvia.', 'Pedido completo'],
                3 => ['Me cambiaron el arroz por otra marca sin avisar.', 'Llegó, pero tardó casi una hora.'],
                2 => ['Faltó una bolsa de leche y tocó reclamar.'],
                1 => ['Llegó todo revuelto y el pan aplastado.'],
            ],
            self::DROGUERIA => [
                5 => ['Me trajeron el acetaminofén en 15 minutos, una bendición con el niño con fiebre.',
                    'Muy buena atención, me explicaron cómo tomar el medicamento.', 'Entrega rápida', 'Discretos y puntuales.',
                    'Buenos precios en vitaminas.', 'Atención amable'],
                4 => ['Bien, aunque no tenían la presentación de 1 g y me mandaron la de 500 mg.', 'Todo bien, el domicilio un poco caro.',
                    'Producto en buen estado'],
                3 => ['Se demoraron más de lo prometido.', 'No me llegó la factura con el pedido.'],
                2 => ['El protector solar venía con la tapa rota.'],
                1 => ['Me mandaron otra marca del medicamento sin preguntar.'],
            ],
            default => [
                5 => ['La bandeja paisa llegó calientica y bien servida.', 'El sancocho sabe a casa, de los mejores de Barranquilla.',
                    'Comida rica y abundante', 'Entrega rápida', 'El corrientazo vale cada peso.', 'Pedí para la oficina y llegó todo completo.'],
                4 => ['Muy rico, pero el jugo llegó sin hielo.', 'Buena sazón, se demoró un poco al mediodía.', 'Porciones generosas'],
                3 => ['El arroz llegó algo frío.', 'Faltaron los cubiertos.'],
                2 => ['La mojarra llegó blanda, no crocante.'],
                1 => ['Tardó más de una hora y llegó frío.'],
            ],
        };

        $domiTextos = [5 => ['Muy amable', 'Llegó rápido', 'Llamó antes de llegar'], 4 => ['Puntual', 'Bien'],
            3 => ['Se demoró encontrando la dirección'], 2 => ['No traía vuelto'], 1 => ['Fue grosero']];

        $cuantas = [1 => 26, 4 => 28, 9 => 36][$negocio] ?? 20;

        $entregados = DB::table('orderssales')
            ->whereIn('orderSales_id', $this->libreta['pedidos'])
            ->where('busines_id', $negocio)
            ->where('state', OrdersSales::ENTREGADO)
            ->where('delivery_date', '<', $this->ahora->copy()->subHour())
            ->orderBy('orderSales_id')
            ->get(['orderSales_id', 'buyer_id', 'domiciliary_id', 'delivery_date'])
            ->all();

        $this->barajar($entregados);

        foreach (array_slice($entregados, 0, $cuantas) as $o) {
            $estrellas = $this->elegir([5 => 56, 4 => 26, 3 => 9, 2 => 5, 1 => 4]);
            $lista = $textos[$estrellas];
            $comentario = mt_rand(1, 100) <= 20 ? null : $lista[mt_rand(0, count($lista) - 1)];

            $cuando = Carbon::parse($o->delivery_date)->addMinutes(mt_rand(10, 300));
            if ($cuando->gt($this->ahora)) {
                $cuando = $this->ahora->copy()->subMinutes(5);
            }

            $this->libreta['resenas'][] = (int) DB::table('business_reviews')->insertGetId([
                'busines_id' => $negocio, 'buyer_id' => $o->buyer_id, 'qualification' => $estrellas,
                'comment' => $comentario, 'state' => 1, 'created_at' => $cuando, 'updated_at' => $cuando,
            ], 'reviews_id');

            DB::table('orderssales')->where('orderSales_id', $o->orderSales_id)->update(['has_review' => 1]);

            if ($o->domiciliary_id && mt_rand(1, 100) <= 55) {
                $e = $this->elegir([5 => 60, 4 => 25, 3 => 8, 2 => 4, 1 => 3]);
                $t = $domiTextos[$e];
                $this->libreta['resenas_domi'][] = (int) DB::table('domiciliary_reviews')->insertGetId([
                    'domiciliary_id' => $o->domiciliary_id, 'buyer_id' => $o->buyer_id, 'qualification' => $e,
                    'comment' => $t[mt_rand(0, count($t) - 1)], 'state' => 1, 'created_at' => $cuando, 'updated_at' => $cuando,
                ], 'reviews_id');
            }
        }
    }

    /** Los usos de cada promoción son los que de verdad se aplicaron. */
    private function usosDePromociones(array $promos): void
    {
        foreach ($promos as $p) {
            DB::table('promotions')->where('promotion_id', $p['id'])->update([
                'usos' => DB::table('orders_promotions')->where('promotion_id', $p['id'])->where('state', 1)->count(),
            ]);
        }
    }

    /** La calificación de cada ficha es el promedio de sus reseñas. */
    private function recalcularCalificaciones(): void
    {
        $negocios = implode(',', array_keys(self::NEGOCIOS));

        DB::statement("
            UPDATE business b
               SET b.qualification = COALESCE((
                     SELECT ROUND(AVG(r.qualification), 2) FROM business_reviews r WHERE r.busines_id = b.busines_id
                   ), 0)
             WHERE b.busines_id IN ({$negocios})
        ");

        $domis = implode(',', array_unique(array_merge(...array_values(self::FLOTAS))));

        DB::statement("
            UPDATE domiciliary d
               SET d.qualification = COALESCE((
                     SELECT ROUND(AVG(r.qualification), 2) FROM domiciliary_reviews r WHERE r.domiciliary_id = d.domiciliary_id
                   ), d.qualification)
             WHERE d.domiciliary_id IN ({$domis})
        ");
    }

    /* ================================================================== */
    /* EL RESUMEN */

    private function resumen(): void
    {
        $hoy = $this->ahora->copy()->startOfDay();
        $semana = $this->ahora->copy()->startOfWeek(Carbon::MONDAY);
        $filas = [];

        foreach (array_keys(self::NEGOCIOS) as $n) {
            $ventas = fn (Carbon $desde) => (float) DB::table('orderssales')
                ->where('busines_id', $n)->where('state', OrdersSales::ENTREGADO)
                ->whereBetween('delivery_date', [$desde, $this->ahora])
                ->sum(DB::raw('subtotal - discount'));
            $entregados = fn (Carbon $desde) => DB::table('orderssales')
                ->where('busines_id', $n)->where('state', OrdersSales::ENTREGADO)
                ->whereBetween('delivery_date', [$desde, $this->ahora])->count();
            $mostrador = fn (Carbon $desde) => (float) DB::table('ventas_mostrador')
                ->where('busines_id', $n)->where('estado_pago', 'pagada')
                ->whereBetween('vendida_at', [$desde, $this->ahora])->sum('total');

            $filas[] = [
                $n,
                DB::table('orderssales')->where('busines_id', $n)->count(),
                DB::table('orderssales_detail as d')->join('orderssales as o', 'o.orderSales_id', '=', 'd.orderSales_id')->where('o.busines_id', $n)->count(),
                DB::table('orderssales')->where('busines_id', $n)->whereIn('state', OrdersSales::ACTIVOS)->count(),
                DB::table('business_reviews')->where('busines_id', $n)->count(),
                DB::table('business_user_affiliations')->where('busines_id', $n)->count(),
                DB::table('store_credits')->where('busines_id', $n)->count(),
                DB::table('promotions')->where('busines_id', $n)->count(),
                DB::table('gastos_del_negocio')->where('busines_id', $n)->count(),
                DB::table('products_business')->where('busines_id', $n)->whereNotNull('costo')->count(),
                DB::table('ventas_mostrador')->where('busines_id', $n)->count(),
                DB::table('cash_deposits')->where('busines_id', $n)->count(),
                DB::table('business_domiciliary')->where('busines_id', $n)->count(),
                $entregados($hoy).' / $'.number_format($ventas($hoy), 0, ',', '.'),
                $entregados($semana).' / $'.number_format($ventas($semana), 0, ',', '.'),
                '$'.number_format($mostrador($semana), 0, ',', '.'),
            ];
        }

        $this->command?->table(
            ['Neg', 'Pedidos', 'Renglones', 'Activos', 'Reseñas', 'Afiliados', 'Fiados', 'Promos', 'Gastos', 'Con costo',
                'Mostrador', 'Entregas ef.', 'Domis', 'Hoy entreg.', 'Semana entreg.', 'Mostrador sem.'],
            $filas,
        );
        $this->command?->line('Libreta: '.$this->rutaLibreta);
    }

    /* ================================================================== */
    /* AYUDAS */

    /** Elige una llave según su peso, con la semilla fija. */
    private function elegir(array $pesos): int|string
    {
        $total = array_sum($pesos);
        $tiro = mt_rand(1, max(1, (int) $total));

        foreach ($pesos as $llave => $peso) {
            $tiro -= $peso;
            if ($tiro <= 0) {
                return $llave;
            }
        }

        return array_key_last($pesos);
    }

    /** `shuffle` con `mt_rand`, para que salga igual en cada corrida. */
    private function barajar(array &$lista): void
    {
        $lista = array_values($lista);
        for ($i = count($lista) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
        }
    }

    private function comoUuid(string $semilla): string
    {
        $h = md5('vecipaya-muestra|'.$semilla);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    private function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
    }
}
