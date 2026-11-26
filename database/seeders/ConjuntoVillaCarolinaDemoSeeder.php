<?php

namespace Database\Seeders;

use App\Models\Conjunto\ComplexStaff;
use App\Models\Order\OrdersSales;
use App\Models\Rol;
use App\Models\User;
use App\Services\Ajustes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * UN MES DE PORTERÍA EN EL CONJUNTO VILLA CAROLINA, PARA ENSEÑAR EL PANEL.
 *
 * El panel del conjunto (Resumen, Entradas, Torres, Residentes, Celadores,
 * Reportes) se alimenta del libro de la portería, y en la base local ese libro
 * está vacío: el tablero sale en ceros y no se puede enseñar.
 *
 * Crea, para el conjunto 3:
 *   · la forma del edificio (torres y apartamentos), si no la tiene;
 *   · tres celadores, por el mismo camino que `CeladoresController`;
 *   · unos treinta residentes con torre y apartamento;
 *   · ~35 días de entradas: domiciliarios de la plataforma (con su pedido
 *     entregado de verdad), visitas, servicio y domicilios de otras apps,
 *     con salida en casi todas y algunas TODAVÍA ADENTRO hoy —un par de
 *     ellas pasadas de las horas de alerta, para que se vea el aviso—;
 *   · los turnos de los celadores (uno EN TURNO AHORA) y, en el historial
 *     de Jhon, una baja y su reactivación de hace unos días.
 *
 * Las entradas de domiciliarios se guardan como las guarda
 * `PorteriaController::verificar`: sin torre propia, con la lista de pedidos
 * congelada en `orders`. Las demás, como `VisitantesController::registrar`:
 * método `manual` y la torre que anotó el celador.
 *
 * Los pedidos son de negocios de demostración (3 y 5); no toca los demás.
 *
 * IDEMPOTENTE. Las personas se reutilizan por correo y lo que cuelga de ellas
 * —entradas registradas por estos celadores, pedidos de estos residentes— se
 * borra y se vuelve a crear. «Hoy» se calcula al correrlo, así que conviene
 * correrlo justo antes de una demostración:
 *
 *     php artisan db:seed --class=ConjuntoVillaCarolinaDemoSeeder
 *
 * Todo lleva correo `@demo.example.com`, así que `DemoPurgeSeeder` lo retira.
 */
class ConjuntoVillaCarolinaDemoSeeder extends Seeder
{
    private const CONJUNTO = 3;

    private const DIAS = 35;

    private const SEMILLA = 20261126;

    private const TORRES = 6;

    private const PISOS = 10;

    private const POR_PISO = 4;

    /** Negocios cuyos pedidos llegan al conjunto, con su peso y sus domiciliarios. */
    private const NEGOCIOS = [
        3 => ['peso' => 80, 'domis' => [3, 7, 9]],
        5 => ['peso' => 20, 'domis' => [3, 5, 9]],
    ];

    /** [correo, nombre, teléfono] */
    private const CELADORES = [
        ['celador.ramiro.vc@demo.example.com', 'Ramiro Pacheco', '301 555 0181'],
        ['celador.yesenia.vc@demo.example.com', 'Yesenia Barrios', '301 555 0182'],
        ['celador.jhon.vc@demo.example.com', 'Jhon Jairo Ariza', '301 555 0183'],
    ];

    private const RESIDENTES = [
        'Andrea Charris', 'Luis Fernando Mejía', 'Carolina De la Hoz', 'Rafael Peñaranda',
        'Diana Marcela Rojas', 'Jorge Iván Cantillo', 'Paola Andrea Gómez', 'Hernán Fontalvo',
        'Kelly Johana Ortiz', 'Álvaro Maestre', 'Natalia Sarmiento', 'Camilo Andrés Pérez',
        'Ingrid Vásquez', 'Gustavo Rada', 'Melissa Torregroza', 'Fabián Ahumada',
        'Liliana Barraza', 'Óscar Iván Pineda', 'Valentina Rúa', 'Germán Altamar',
        'Shirley Cassiani', 'Mauricio Lozano', 'Daniela Palencia', 'Eduardo Navarro',
        'Yolanda Mercado', 'Sebastián Herrera', 'María José Polo', 'Ricardo Bolaño',
        'Tatiana Coronado', 'Alejandro Cueto', 'Lorena Fuentes', 'Kevin Arrieta',
    ];

    private const NOMBRES = [
        'Carlos', 'María', 'José', 'Ana', 'Luis', 'Laura', 'Jesús', 'Sandra', 'Andrés', 'Claudia',
        'Pedro', 'Martha', 'Juan', 'Luz', 'Miguel', 'Rosa', 'Javier', 'Paola', 'Wilson', 'Yuli',
        'Edwin', 'Katherine', 'Jairo', 'Milena', 'Óscar', 'Daniela', 'Nelson', 'Viviana',
    ];

    private const APELLIDOS = [
        'Pérez', 'Gómez', 'Rodríguez', 'Martínez', 'Herrera', 'Castro', 'Ospino', 'Barrios',
        'Mendoza', 'Charris', 'Polo', 'Ariza', 'De la Rosa', 'Orozco', 'Navarro', 'Molina',
        'Suárez', 'Escorcia', 'Fontalvo', 'Jiménez', 'Rada', 'Torres', 'Vargas', 'Cantillo',
    ];

    private const SERVICIOS = [
        ['Técnico de gas domiciliario', 'Revisión periódica del gas'],
        ['Internet y TV por cable', 'Instalación de fibra'],
        ['Energía (lectura de contador)', null],
        ['Plomería La Costa', 'Arreglo de fuga en el baño'],
        ['Mudanzas Caribe', 'Mudanza, usa el ascensor de carga'],
        ['Fumigaciones del Norte', 'Fumigación programada'],
        ['Mantenimiento de ascensores', 'Mantenimiento mensual'],
        ['Electricista independiente', 'Cambio de breakers'],
        ['Aseo por días', 'Empleada de servicio'],
        ['Cerrajería 24 horas', null],
    ];

    private const OTRAS_APPS = ['Rappi', 'DiDi Food', 'Domicilio de restaurante', 'Mensajería', 'iFood'];

    /** Peso de cada hora del día: picos al almuerzo y en la noche. */
    private const HORAS = [
        0 => 0.4, 1 => 0.15, 2 => 0, 3 => 0, 4 => 0, 5 => 0.3,
        6 => 1.2, 7 => 2.5, 8 => 3, 9 => 3, 10 => 3.5, 11 => 4.5,
        12 => 8, 13 => 8, 14 => 5, 15 => 4, 16 => 4, 17 => 5,
        18 => 8, 19 => 9.5, 20 => 8, 21 => 5, 22 => 2, 23 => 0.8,
    ];

    private float $tarifa;

    private float $reparto;

    private float $comision;

    /** @var array<int, int> user_id de cada celador, en orden */
    private array $celadores = [];

    /** @var list<array{buyer_id:int, address_id:int, tower:string, apartment:string, name:string}> */
    private array $residentes = [];

    /** @var array<int, Collection> */
    private array $catalogo = [];

    /** @var array<int, object> */
    private array $negocios = [];

    private CarbonImmutable $ahora;

    public function run(): void
    {
        $conjunto = DB::table('residential_complexes')->where('complex_id', self::CONJUNTO)->first();

        if (! $conjunto) {
            $this->command?->error('No existe el conjunto '.self::CONJUNTO.'.');

            return;
        }

        $clave = env('SEED_PASSWORD');

        if (! $clave) {
            $this->command?->error('Falta SEED_PASSWORD en el .env.');

            return;
        }

        mt_srand(self::SEMILLA);
        $this->ahora = CarbonImmutable::now();

        $this->tarifa = (float) (Ajustes::valor('operacion.tarifa_domicilio') ?? 5000);
        $this->reparto = (float) (Ajustes::valor('operacion.reparto_domiciliario') ?? 0.75);
        $this->comision = (float) (Ajustes::valor('operacion.comision_plataforma') ?? 0.03);

        foreach (array_keys(self::NEGOCIOS) as $id) {
            $this->negocios[$id] = DB::table('business')->where('busines_id', $id)->first(['busines_id', 'name', 'logo']);
            $this->catalogo[$id] = DB::table('products_business as pb')
                ->join('products as p', 'p.products_id', '=', 'pb.products_id')
                ->where('pb.busines_id', $id)
                ->where('pb.price', '>', 0)
                ->orderBy('p.products_id')
                ->limit(120)
                ->get(['p.products_id', 'p.name', 'pb.price']);
        }

        DB::transaction(function () use ($conjunto, $clave) {
            $this->formaDelConjunto($conjunto);
            $this->crearCeladores($clave);
            $this->crearResidentes($clave, $conjunto);
            $this->limpiarLoAnterior();
            $this->sembrarEntradas();
            $this->sembrarTurnos();
            $this->historialDeCuentas();
        });

        $this->informar();
    }

    /* --------------------------- EL EDIFICIO --------------------------- */

    private function formaDelConjunto(object $conjunto): void
    {
        $cambios = array_filter([
            'towers_count' => $conjunto->towers_count ?: self::TORRES,
            'apartments_per_tower' => $conjunto->apartments_per_tower ?: self::PISOS * self::POR_PISO,
            'people_count' => $conjunto->people_count ?: 480,
            'gate_notes' => $conjunto->gate_notes ?: 'Domicilios hasta la portería después de las 10 p. m. '
                .'Mudanzas solo con aviso a la administración y por el ascensor de carga. '
                .'Visitas: anunciar por citófono antes de dejar pasar.',
            'phone' => $conjunto->phone ?: '605 555 0170',
            'admin_name' => $conjunto->admin_name ?: 'Administración Villa Carolina',
        ]);

        // Que quede constancia de quién autorizó cada visita: es lo que una
        // administración pide, y en la demostración se ve el campo lleno.
        $cambios['require_authorization'] = 1;
        $cambios['updated_at'] = now();

        DB::table('residential_complexes')->where('complex_id', self::CONJUNTO)->update($cambios);
    }

    /* --------------------------- LAS PERSONAS --------------------------- */

    /** Igual que `CeladoresController::crearCelador`: usuario rol 6 y su ficha. */
    private function crearCeladores(string $clave): void
    {
        Rol::firstOrCreate(['rol_id' => ComplexStaff::ROL_CELADOR], ['name' => 'celador', 'guard_name' => 'web']);

        $dueno = ComplexStaff::where('complex_id', self::CONJUNTO)
            ->where('role', ComplexStaff::DUENO)->value('user_id');

        foreach (self::CELADORES as [$correo, $nombre, $telefono]) {
            $user = User::where('email', $correo)->first() ?? User::create([
                'name' => $nombre,
                'email' => $correo,
                'password' => Hash::make($clave),
                'phone' => $telefono,
                'rol' => ComplexStaff::ROL_CELADOR,
                'state' => true,
                'email_verified_at' => now(),
            ]);

            ComplexStaff::updateOrCreate(
                ['user_id' => $user->user_id],
                [
                    'complex_id' => self::CONJUNTO,
                    'role' => ComplexStaff::CELADOR,
                    'state' => true,
                    'created_by' => $dueno,
                ],
            );

            // La clave del demo la conoce quien la enseña: entra sin pasar por
            // «Crea tu clave». Y el alta, un poco antes del libro, para que
            // el historial empiece por ahí.
            $user->forceFill(['must_change_password' => false])->save();
            DB::table('complex_staff')->where('user_id', $user->user_id)
                ->update(['created_at' => $this->ahora->subDays(self::DIAS + 5)]);

            $this->celadores[] = (int) $user->user_id;
        }
    }

    /** Los residentes: comprador, vínculo con el conjunto y su dirección adentro. */
    private function crearResidentes(string $clave, object $conjunto): void
    {
        $ocupados = [];

        foreach (self::RESIDENTES as $i => $nombre) {
            $correo = 'vc.'.$this->slug($nombre).'@demo.example.com';

            // Torre y apartamento fijos por residente, sin repetir puerta.
            do {
                $torre = (string) mt_rand(1, self::TORRES);
                $apto = mt_rand(1, self::PISOS).'0'.mt_rand(1, self::POR_PISO);
            } while (isset($ocupados["$torre|$apto"]));
            $ocupados["$torre|$apto"] = true;

            // Unos llevan meses, otros llegaron este mes: «nuevos este mes».
            $desde = $this->ahora->subDays($i < 6 ? mt_rand(1, 20) : mt_rand(35, 200))->setTime(mt_rand(7, 21), mt_rand(0, 59));

            $user = User::where('email', $correo)->first() ?? User::create([
                'name' => $nombre,
                'email' => $correo,
                'password' => Hash::make($clave),
                'phone' => '300 555 '.str_pad((string) (200 + $i), 4, '0', STR_PAD_LEFT),
                'rol' => 1,
                'state' => 1,
                'email_verified_at' => $desde,
            ]);

            $buyerId = DB::table('buyer')->where('user_id', $user->user_id)->value('buyer_id')
                ?? DB::table('buyer')->insertGetId([
                    'user_id' => $user->user_id,
                    'qualification' => 0,
                    'belongs_to_complex' => 1,
                    'state' => 1,
                ]);

            DB::table('buyer_complex')->updateOrInsert(
                ['buyer_id' => $buyerId, 'complex_id' => self::CONJUNTO],
                ['created_at' => $desde, 'updated_at' => $desde],
            );

            $direccion = DB::table('user_address')
                ->where('user_id', $user->user_id)
                ->where('complex_id', self::CONJUNTO)
                ->first();

            if ($direccion) {
                $torre = (string) $direccion->tower;
                $apto = (string) $direccion->apartment;
                $addressId = (int) $direccion->address_id;
            } else {
                $addressId = DB::table('user_address')->insertGetId([
                    'user_id' => $user->user_id,
                    'address' => $conjunto->address ?: 'Conjunto Villa Carolina',
                    'complex_id' => self::CONJUNTO,
                    'tower' => $torre,
                    'apartment' => $apto,
                    'latitude' => $conjunto->latitude,
                    'longitude' => $conjunto->longitude,
                    'municipality_id' => $conjunto->municipality_id,
                    'state' => 1,
                    'nombre' => 'Casa',
                ], 'address_id');
            }

            $this->residentes[] = [
                'buyer_id' => (int) $buyerId,
                'address_id' => $addressId,
                'tower' => $torre,
                'apartment' => $apto,
                'name' => $nombre,
            ];
        }
    }

    /** Lo que creó una corrida anterior: entradas de estos celadores y pedidos de estos vecinos. */
    private function limpiarLoAnterior(): void
    {
        DB::table('complex_entries')
            ->where('complex_id', self::CONJUNTO)
            ->whereIn('registered_by', $this->celadores)
            ->delete();

        $pedidos = DB::table('orderssales')
            ->whereIn('buyer_id', array_column($this->residentes, 'buyer_id'))
            ->pluck('orderSales_id');

        foreach ($pedidos->chunk(500) as $lote) {
            DB::table('invoices')->whereIn('orderSales_id', $lote)->delete();
            DB::table('payments')->whereIn('orderSales_id', $lote)->delete();
            DB::table('orderssales_detail')->whereIn('orderSales_id', $lote)->delete();
            DB::table('orderssales')->whereIn('orderSales_id', $lote)->delete();
        }
    }

    /* --------------------------- EL LIBRO --------------------------- */

    private function sembrarEntradas(): void
    {
        $hoy = $this->ahora->startOfDay();

        for ($n = self::DIAS - 1; $n >= 0; $n--) {
            $dia = $hoy->subDays($n);
            $finde = $dia->isWeekend();

            // Más movimiento el fin de semana y los viernes; algo de ruido
            // para que ningún día sea igual a su semana anterior.
            $volumen = $finde ? mt_rand(32, 45) : ($dia->isFriday() ? mt_rand(26, 38) : mt_rand(17, 31));

            $horas = [];
            for ($k = 0; $k < $volumen; $k++) {
                $horas[] = $dia->setTime($this->horaAlAzar(), mt_rand(0, 59), mt_rand(0, 59));
            }

            if ($n === 0) {
                // Hoy sólo hasta este momento.
                $horas = array_values(array_filter($horas, fn ($h) => $h->lessThan($this->ahora->subMinutes(2))));
            }

            sort($horas);

            foreach ($horas as $cuando) {
                $this->entrada($this->claseAlAzar($finde), $cuando);
            }
        }

        $this->siguenAdentro();
    }

    /** Que hoy haya gente adentro, sea la hora que sea: dos domiciliarios y dos visitas. */
    private function siguenAdentro(): void
    {
        $hoy = fn ($q) => $q->where('complex_id', self::CONJUNTO)
            ->where('created_at', '>=', $this->ahora->startOfDay())
            ->whereNull('exited_at');

        $minutosDeHoy = max(3, (int) $this->ahora->startOfDay()->diffInMinutes($this->ahora));
        $hace = fn (int $min) => $this->ahora->subMinutes(min($min, $minutosDeHoy - 1));

        $domis = DB::table('complex_entries')->tap($hoy)->where('kind', 'domiciliario')->count();
        foreach ([4, 9] as $i => $min) {
            if ($domis + $i < 2) {
                $this->entrada('domiciliario', $hace($min), adentro: true);
            }
        }

        $otros = DB::table('complex_entries')->tap($hoy)->where('kind', '!=', 'domiciliario')->count();
        foreach ([25, 70, 15] as $i => $min) {
            if ($otros + $i < 3) {
                $this->entrada($i === 2 ? 'servicio' : 'visitante', $hace($min), adentro: true);
            }
        }

        // Dos que llevan más de las 4 h de alerta: una visita de cinco horas
        // y un técnico de seis y media. Si el día apenas empieza, quedan con
        // lo que dé el día (sin pasarse a ayer, que no cuenta como adentro).
        $this->entrada('visitante', $hace(300), adentro: true);
        $this->entrada('servicio', $hace(390), adentro: true);
    }

    /* --------------------------- LOS TURNOS --------------------------- */

    /**
     * Un turno de mañana (6 a 14) y uno de tarde y noche (14 a 6) por día,
     * del celador que `celadorDeTurno()` pone a esa hora. El que corre ahora
     * queda ABIERTO: en Celadores sale «En turno ahora» y en la portería de
     * ese celador, «llevas 3 h».
     */
    private function sembrarTurnos(): void
    {
        DB::table('complex_shifts')
            ->where('complex_id', self::CONJUNTO)
            ->whereIn('user_id', $this->celadores)
            ->delete();

        $filas = [];
        $primero = $this->ahora->startOfDay()->subDays(self::DIAS);

        for ($dia = $primero; $dia->lessThanOrEqualTo($this->ahora); $dia = $dia->addDay()) {
            foreach ([[6, 8], [14, 16]] as [$hora, $largo]) {
                $inicio = $dia->setTime($hora, 0)->subMinutes(mt_rand(0, 12));
                $fin = $dia->setTime($hora, 0)->addHours($largo)->addMinutes(mt_rand(0, 15));

                if ($inicio->greaterThan($this->ahora)) {
                    continue;
                }

                $filas[] = [
                    'complex_id' => self::CONJUNTO,
                    'user_id' => $this->celadorDeTurno($dia->setTime($hora, 30)),
                    'started_at' => $inicio,
                    // El que todavía corre queda abierto.
                    'ended_at' => $fin->greaterThan($this->ahora) ? null : $fin,
                    'created_at' => $inicio,
                    'updated_at' => $fin->greaterThan($this->ahora) ? $inicio : $fin,
                ];
            }
        }

        foreach (array_chunk($filas, 200) as $lote) {
            DB::table('complex_shifts')->insert($lote);
        }
    }

    /**
     * Una baja y su reactivación en la ficha de Jhon, como las deja la
     * auditoría al desactivarlo desde Celadores. El paquete no audita en
     * consola, así que se escriben a mano, marcadas para poder borrarlas.
     */
    private function historialDeCuentas(): void
    {
        $jhon = DB::table('complex_staff')->where('user_id', $this->celadores[2])->value('id');
        $dueno = ComplexStaff::where('complex_id', self::CONJUNTO)->where('role', ComplexStaff::DUENO)->value('user_id');

        DB::table('audits')
            ->where('auditable_type', (new ComplexStaff)->getMorphClass())
            ->where('auditable_id', $jhon)
            ->where('tags', 'demo-villa-carolina')
            ->delete();

        foreach ([[2, true, false], [1, false, true]] as [$hace, $antes, $despues]) {
            $cuando = $this->ahora->subDays($hace)->setTime(9, 15);
            DB::table('audits')->insert([
                'user_type' => $dueno ? User::class : null,
                'user_id' => $dueno,
                'event' => 'updated',
                'auditable_type' => (new ComplexStaff)->getMorphClass(),
                'auditable_id' => $jhon,
                'old_values' => json_encode(['state' => $antes]),
                'new_values' => json_encode(['state' => $despues]),
                'url' => 'demo',
                'tags' => 'demo-villa-carolina',
                'created_at' => $cuando,
                'updated_at' => $cuando,
            ]);
        }
    }

    private function entrada(string $clase, CarbonImmutable $cuando, bool $adentro = false): void
    {
        $celador = $this->celadorDeTurno($cuando);

        $fila = [
            'complex_id' => self::CONJUNTO,
            'kind' => $clase,
            'domiciliary_id' => null,
            'method' => 'manual',
            'orders_count' => 0,
            'orders' => null,
            'registered_by' => $celador,
            'created_at' => $cuando,
            'updated_at' => $cuando,
        ];

        [$min, $max] = match ($clase) {
            'domiciliario' => [4, 14],
            'domicilio_externo' => [3, 10],
            'servicio' => [40, 180],
            default => [25, 240],
        };
        $salida = $cuando->addMinutes(mt_rand($min, $max))->addSeconds(mt_rand(0, 59));

        if ($clase === 'domiciliario') {
            $fila = $this->conPedidos($fila, $cuando, $salida, $adentro || $salida->greaterThan($this->ahora));
        } else {
            $fila = array_merge($fila, $this->visitante($clase));
        }

        // Una de cada cuarenta, en días pasados, sin salida anotada: pasa en
        // toda portería, y el libro lo tiene que aguantar.
        $olvido = ! $cuando->isToday() && mt_rand(1, 40) === 1;

        if (! $adentro && ! $olvido && $salida->lessThanOrEqualTo($this->ahora)) {
            $fila['exited_at'] = $salida;
            $fila['exit_registered_by'] = $this->celadorDeTurno($salida);
            $fila['updated_at'] = $salida;
        }

        DB::table('complex_entries')->insert($fila);
    }

    /** El domiciliario y sus pedidos, congelados como los guarda la portería. */
    private function conPedidos(array $fila, CarbonImmutable $entra, CarbonImmutable $sale, bool $enCamino): array
    {
        $negocio = $this->alAzarPonderado(array_map(fn ($n) => $n['peso'], self::NEGOCIOS));
        $domi = self::NEGOCIOS[$negocio]['domis'][array_rand(self::NEGOCIOS[$negocio]['domis'])];

        // A veces trae dos: del mismo negocio o de otro que también atiende.
        $negocios = [$negocio];
        if (mt_rand(1, 100) <= 15) {
            $otros = array_keys(array_filter(self::NEGOCIOS, fn ($n) => in_array($domi, $n['domis'], true)));
            $negocios[] = $otros[array_rand($otros)];
        }

        $pedidos = [];
        foreach ($negocios as $b) {
            $pedidos[] = $this->pedido($b, $domi, $entra, $sale, $enCamino);
        }

        // Por código la mayoría; la cédula, cuando el código no le carga.
        $fila['domiciliary_id'] = $domi;
        $fila['method'] = mt_rand(1, 100) <= 72 ? 'codigo' : 'cedula';
        $fila['orders_count'] = count($pedidos);
        $fila['orders'] = json_encode($pedidos, JSON_UNESCAPED_UNICODE);

        return $fila;
    }

    /** Un pedido de verdad, entregado (o en camino si sigue adentro). */
    private function pedido(int $businessId, int $domi, CarbonImmutable $entra, CarbonImmutable $sale, bool $enCamino): array
    {
        $vecino = $this->residentes[array_rand($this->residentes)];

        $lineas = [];
        $subtotal = 0;
        $catalogo = $this->catalogo[$businessId];

        if ($catalogo->isNotEmpty()) {
            foreach ((array) array_rand($catalogo->all(), min($catalogo->count(), mt_rand(1, 3))) as $k) {
                $p = $catalogo[$k];
                $cant = mt_rand(1, 2);
                $subtotal += (float) $p->price * $cant;
                $lineas[] = ['product_id' => $p->products_id, 'name' => $p->name, 'amount' => $cant, 'unit_price' => (float) $p->price];
            }
        }

        if ($subtotal <= 0) {
            $subtotal = mt_rand(15, 60) * 1000;
        }

        $total = $subtotal + $this->tarifa;
        $pago = mt_rand(1, 100);
        [$metodo, $forma] = $pago <= 45 ? [1, 1] : ($pago <= 80 ? [6, 2] : [2, 2]);
        $efectivo = $metodo === 1;

        $despacho = $entra->subMinutes(mt_rand(8, 18));
        $listo = $despacho->subMinutes(mt_rand(1, 4));
        $venta = $listo->subMinutes(mt_rand(8, 20));
        $entrega = $sale->subMinutes(mt_rand(1, 3))->max($entra->addMinute());

        $id = DB::table('orderssales')->insertGetId([
            'buyer_id' => $vecino['buyer_id'],
            'busines_id' => $businessId,
            'domiciliary_id' => $domi,
            'address_id' => $vecino['address_id'],
            'methods_id' => $metodo,
            'forms_id' => $forma,
            'subtotal' => $subtotal,
            'discount' => 0,
            'domicilio' => $this->tarifa,
            'total' => $total,
            'domiciliary_fee' => round($this->tarifa * $this->reparto),
            'platform_fee' => round($subtotal * $this->comision, 2),
            'delivery_subsidy' => 0,
            'cash_due' => $efectivo ? $total : 0,
            'cash_tendered' => $efectivo && ! $enCamino ? $total : null,
            'cash_received' => $efectivo && ! $enCamino ? $total : null,
            'change_given' => $efectivo && ! $enCamino ? 0 : null,
            'currency' => 'COP',
            'sale_date' => $venta,
            'state' => $enCamino ? OrdersSales::EN_CAMINO : OrdersSales::ENTREGADO,
            'payment_state' => $efectivo && $enCamino ? 'pending' : 'paid',
            'pickup' => 0,
            'is_scheduled' => 0,
            'ready_at' => $listo,
            'dispatched_at' => $despacho,
            'delivery_date' => $enCamino ? null : $entrega,
            'promised_minutes' => 45,
            'stock_descontado' => 0,
            'created_at' => $venta,
            'updated_at' => $enCamino ? $despacho : $entrega,
        ], 'orderSales_id');

        foreach ($lineas as $l) {
            DB::table('orderssales_detail')->insert([
                'orderSales_id' => $id,
                'product_id' => $l['product_id'],
                'amount' => $l['amount'],
                'unit_price' => $l['unit_price'],
            ]);
        }

        // La misma forma que `AccesoAlConjunto::pedidosEnElConjunto`.
        return [
            'orderSales_id' => $id,
            'total' => number_format($total, 2, '.', ''),
            'methods_id' => $metodo,
            'tower' => $vecino['tower'],
            'apartment' => $vecino['apartment'],
            'business_name' => $this->negocios[$businessId]->name ?? null,
            'business_logo' => $this->negocios[$businessId]->logo ?? null,
            'items' => array_map(fn ($l) => [
                'name' => $l['name'],
                'amount' => $l['amount'],
                'unit_price' => $l['unit_price'],
                'line_total' => null,
                'sale_unit' => 'unidad',
                'qty_requested' => null,
                'qty_final' => null,
            ], $lineas),
            'items_count' => array_sum(array_column($lineas, 'amount')),
        ];
    }

    /** Lo que anota el celador de quien no es usuario de la plataforma. */
    private function visitante(string $clase): array
    {
        // Siete de cada diez van a casa de un residente registrado.
        if (mt_rand(1, 10) <= 7) {
            $v = $this->residentes[array_rand($this->residentes)];
            [$torre, $apto, $autoriza] = [$v['tower'], $v['apartment'], $v['name']];
        } else {
            $torre = (string) mt_rand(1, self::TORRES);
            $apto = mt_rand(1, self::PISOS).'0'.mt_rand(1, self::POR_PISO);
            $autoriza = 'Residente del '.$torre.'-'.$apto.' (citófono)';
        }

        $nombre = self::NOMBRES[array_rand(self::NOMBRES)].' '
            .self::APELLIDOS[array_rand(self::APELLIDOS)].' '
            .self::APELLIDOS[array_rand(self::APELLIDOS)];

        $datos = [
            'visitor_name' => $nombre,
            'visitor_document' => (string) mt_rand(10000000, 1199999999),
            'visitor_phone' => mt_rand(1, 3) === 1 ? null : '3'.mt_rand(0, 2).mt_rand(0, 9).' '.mt_rand(100, 999).' '.mt_rand(1000, 9999),
            'visitor_company' => null,
            'vehicle_plate' => null,
            'tower' => $torre,
            'apartment' => $apto,
            'authorized_by' => $autoriza,
            'notes' => null,
        ];

        if ($clase === 'servicio') {
            [$empresa, $nota] = self::SERVICIOS[array_rand(self::SERVICIOS)];
            $datos['visitor_company'] = $empresa;
            $datos['notes'] = $nota;
            $datos['vehicle_plate'] = mt_rand(1, 2) === 1 ? $this->placa() : null;
        } elseif ($clase === 'domicilio_externo') {
            $datos['visitor_company'] = self::OTRAS_APPS[array_rand(self::OTRAS_APPS)];
            $datos['vehicle_plate'] = mt_rand(1, 3) > 1 ? $this->placa(moto: true) : null;
            $datos['visitor_document'] = mt_rand(1, 2) === 1 ? $datos['visitor_document'] : null;
        } else {
            $datos['vehicle_plate'] = mt_rand(1, 4) === 1 ? $this->placa() : null;
            if (mt_rand(1, 12) === 1) {
                $datos['notes'] = 'Viene a la piscina con el residente.';
            }
        }

        return $datos;
    }

    /* --------------------------- AZAR CON FORMA --------------------------- */

    /** Ramiro de mañana, Yesenia de tarde y noche; Jhon releva un día de cada cuatro y los domingos. */
    private function celadorDeTurno(CarbonImmutable $cuando): int
    {
        $relevo = $cuando->isSunday() || $cuando->dayOfYear % 4 === 0;
        $turno = $cuando->hour >= 6 && $cuando->hour < 14 ? 0 : 1;

        if ($relevo && $turno === ($cuando->dayOfYear % 2)) {
            return $this->celadores[2];
        }

        return $this->celadores[$turno];
    }

    private function claseAlAzar(bool $finde): string
    {
        return $this->alAzarPonderado($finde
            ? ['domiciliario' => 33, 'visitante' => 37, 'servicio' => 7, 'domicilio_externo' => 23]
            : ['domiciliario' => 38, 'visitante' => 24, 'servicio' => 16, 'domicilio_externo' => 22]);
    }

    private function horaAlAzar(): int
    {
        return (int) $this->alAzarPonderado(array_map(fn ($p) => (int) round($p * 10), self::HORAS));
    }

    /** @param array<array-key, int|float> $pesos */
    private function alAzarPonderado(array $pesos): int|string
    {
        $r = mt_rand(1, (int) array_sum($pesos));

        foreach ($pesos as $clave => $peso) {
            $r -= $peso;
            if ($r <= 0) {
                return $clave;
            }
        }

        return array_key_first($pesos);
    }

    private function placa(bool $moto = false): string
    {
        $letras = fn ($n) => implode('', array_map(fn () => chr(mt_rand(65, 90)), range(1, $n)));

        return $moto
            ? $letras(3).str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT).$letras(1)
            : $letras(3).str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    }

    private function slug(string $nombre): string
    {
        $sin = strtr(mb_strtolower($nombre), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return trim(preg_replace('/[^a-z]+/', '.', $sin), '.');
    }

    /* --------------------------- EL INFORME --------------------------- */

    private function informar(): void
    {
        $base = DB::table('complex_entries')->where('complex_id', self::CONJUNTO);
        $hoy = (clone $base)->where('created_at', '>=', $this->ahora->startOfDay());

        $this->command?->info('Conjunto Villa Carolina listo para la demostración.');
        $this->command?->table(['Dato', 'Valor'], [
            ['Celadores', count($this->celadores)],
            ['Residentes creados', count($this->residentes)],
            ['Entradas (total)', (clone $base)->count()],
            ['Entradas hoy', (clone $hoy)->count()],
            ['Siguen adentro', (clone $hoy)->whereNull('exited_at')->count()],
            ['Llevan más de 4 h', (clone $hoy)->whereNull('exited_at')->where('created_at', '<=', $this->ahora->subHours(4))->count()],
            ['En turno ahora', DB::table('complex_shifts as s')->join('user as u', 'u.user_id', '=', 's.user_id')
                ->where('s.complex_id', self::CONJUNTO)->whereNull('s.ended_at')->pluck('u.name')->implode(', ')],
            ['Pedidos de los residentes', DB::table('orderssales')->whereIn('buyer_id', array_column($this->residentes, 'buyer_id'))->count()],
        ]);
        $this->command?->line('Celadores: '.implode(', ', array_column(self::CELADORES, 0)).' (clave: SEED_PASSWORD).');
    }
}
