<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Services\LiquidacionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * DATOS DE PRUEBA PARA RECORRER EL PANEL ENTERO
 *
 * Deja cada pantalla con algo que mostrar y, sobre todo, con los casos que
 * importan: un domiciliario con el SOAT vencido, un pedido atascado, una PQRS
 * fuera de plazo, un cupón agotado. Una tabla llena de filas idénticas no
 * prueba nada; lo que se revisa al mirar una pantalla es si el caso raro se
 * distingue del normal.
 *
 * TODO lo que crea queda marcado:
 *   · las personas, por el dominio del correo (@demo.example.com);
 *   · los negocios, por el NIT (DEMO-…);
 *   · el resto, porque cuelga de esos dos.
 *
 * Esa marca es lo que permite borrarlo después sin tocar lo real:
 * `php artisan db:seed --class=DemoPurgeSeeder`.
 *
 * Es idempotente: reejecutarlo actualiza en vez de duplicar, y el azar va con
 * semilla fija, así que dos ejecuciones producen exactamente los mismos datos.
 */
class DemoSeeder extends Seeder
{
    /*
     * `example.com`, no `.test`.
     *
     * Los dos están reservados y ninguno puede recibir correo de verdad, que es
     * lo que hace falta para datos de prueba. La diferencia está en la
     * pasarela: Bold rechaza `.test` —`PI_001: value is not a valid email
     * address`— y no crea la orden de pago, así que con las cuentas de
     * demostración era imposible probar un pago de principio a fin. Se
     * comprobó contra la API: `.test` lo rechaza y `example.com` lo acepta.
     *
     * Sigue siendo la marca por la que `DemoPurgeSeeder` distingue lo de
     * mentira de lo real, así que el dominio tiene que quedarse siendo uno
     * imposible de registrar.
     */
    public const DOMINIO = 'demo.example.com';
    public const CLAVE   = 'Demo2026*';
    public const NIT     = 'DEMO-';

    /*
     * TODOS los dominios con los que se sembró alguna vez, no solo el de hoy.
     *
     * Al cambiar de `.test` a `example.com` —por lo de Bold, acá arriba— la
     * purga se quedó mirando solo el nuevo, y en cualquier base sembrada antes
     * del cambio la tanda vieja se volvió indeleble: `DemoPurgeSeeder` no la
     * reconoce y `DemoSeeder` no la pisa, porque inserta por correo y esos
     * correos ya no los usa nadie.
     *
     * Se ve enseguida en el panel del tendero: sus tres domiciliarios salen
     * seis veces, cada uno con su gemelo, y la tarjeta dice 6. No es un fallo
     * de la consulta —los seis existen— pero para quien mira la pantalla es
     * indistinguible de uno.
     *
     * Por eso la lista, y por eso crece hacia atrás: el día que el dominio
     * vuelva a cambiar, el de hoy se queda acá y lo sembrado sigue siendo
     * borrable.
     */
    public const DOMINIOS_HISTORICOS = [
        self::DOMINIO,
        'demo.enviaya.test',
    ];

    /** Barranquilla y su área metropolitana, que es donde opera la plataforma. */
    private const MUNICIPIOS = [16 => 'Barranquilla', 17 => 'Soledad', 18 => 'Malambo'];

    private Carbon $hoy;

    public function run(): void
    {
        /*
         * NO en producción, y sin excepción por descuido.
         *
         * Crea catorce personas con clave conocida, seis negocios y noventa
         * pedidos falsos. Metido en el servidor de verdad, la clave compartida
         * son catorce puertas abiertas y los pedidos falsos ensucian toda la
         * contabilidad. Un `db:seed --force` en un despliegue lo haría sin que
         * nadie se enterara.
         */
        if (app()->isProduction() && !config('semillas.permitir_en_produccion')) {
            $this->command?->warn(
                'DemoSeeder no corre en producción: son datos y usuarios falsos, '
                . 'con una clave compartida y conocida.',
            );

            return;
        }

        // Semilla fija: sin esto, cada ejecución cambiaría los importes y no se
        // podría comparar una pantalla con la de ayer.
        mt_srand(20260816);
        $this->hoy = Carbon::today();

        $this->command?->info('Sembrando datos de demostración…');

        $areas        = $this->personalPorArea();
        $conjuntos    = $this->conjuntos();
        $propietarios = $this->propietarios();
        $negocios     = $this->negocios($propietarios);
        $this->catalogo($negocios);
        $domiciliarios = $this->domiciliarios($negocios);
        $compradores   = $this->compradores($conjuntos);
        $pedidos       = $this->pedidos($negocios, $domiciliarios, $compradores);
        $this->pagos($pedidos);
        $this->resenas($negocios, $domiciliarios, $compradores);
        $this->conversaciones($negocios, $domiciliarios, $compradores);
        $this->marketing($negocios);
        $this->sst($domiciliarios);
        $this->calidad($compradores, $negocios, $domiciliarios, $pedidos, $areas);
        $this->liquidaciones($negocios, $domiciliarios, $areas);

        $this->resumen($areas, $negocios, $domiciliarios, $compradores, $pedidos);
    }

    /* ================================================================== */
    /* PERSONAS DEL EQUIPO                                                */

    /**
     * Dos cuentas por área: quien gestiona y quien solo consulta.
     *
     * El par es el punto. Con una sola cuenta por área no se ve la diferencia
     * entre "esta sección no es tuya" y "esta sección es tuya pero solo para
     * mirar", que son los dos motivos distintos por los que el panel esconde
     * algo.
     *
     * @return array<string, int> código de área => user_id del gestor
     */
    private function personalPorArea(): array
    {
        $gestores = [];

        $nombres = [
            'sistema'      => ['Diego Salazar', 'Valentina Ruiz'],
            'gerencia'     => ['Marta Villalba', 'Andrés Peña'],
            'contabilidad' => ['Liliana Cortés', 'Óscar Meza'],
            'marketing'    => ['Camila Restrepo', 'Julián Ospina'],
            'comercial'    => ['Ricardo Amaya', 'Paola Guerrero'],
            'sst'          => ['Sandra Bermúdez', 'Kevin Arrieta'],
            'calidad'      => ['Natalia Cabrera', 'Hernán Pardo'],
        ];

        foreach (Area::orderBy('id')->get() as $area) {
            [$nombreGestor, $nombreAuxiliar] = $nombres[$area->code] ?? ['Gestor', 'Auxiliar'];

            $gestores[$area->code] = $this->usuario([
                'name'         => $nombreGestor,
                'email'        => "{$area->code}@" . self::DOMINIO,
                'rol'          => 4,
                'area_id'      => $area->id,
                'access_level' => Area::NIVEL_GESTOR,
                'phone'        => $this->telefono(),
            ]);

            $this->usuario([
                'name'         => $nombreAuxiliar,
                'email'        => "aux.{$area->code}@" . self::DOMINIO,
                'rol'          => 4,
                'area_id'      => $area->id,
                'access_level' => Area::NIVEL_CONSULTA,
                'phone'        => $this->telefono(),
            ]);
        }

        return $gestores;
    }

    /* ================================================================== */
    /* COMUNIDAD                                                          */

    /** @return list<int> complex_id */
    private function conjuntos(): array
    {
        $datos = [
            ['Conjunto Villa Carolina', 'Calle 79 #42-15', 16, 480, 10.9962, -74.8070, 1],
            ['Portal de Alameda',       'Carrera 51B #94-30', 16, 620, 11.0119, -74.8158, 1],
            ['Urbanización La Arboleda', 'Calle 30 #18-44', 17, 350, 10.9174, -74.7669, 1],
            ['Conjunto Miramar',        'Carrera 46 #84-12', 16, 210, 11.0044, -74.8093, 1],
            // Inactivo a propósito: el filtro de estado tiene que tener algo que
            // filtrar, y sin coordenadas para que se vea el aviso de "sin ubicar".
            ['Altos de Malambo',        'Calle 12 #7-20', 18, 140, null, null, 0],
        ];

        $ids = [];

        foreach ($datos as [$nombre, $direccion, $municipio, $gente, $lat, $lng, $estado]) {
            DB::table('residential_complexes')->updateOrInsert(
                ['name' => $nombre],
                [
                    'address'         => $direccion,
                    'municipality_id' => $municipio,
                    'people_count'    => $gente,
                    'latitude'        => $lat,
                    'longitude'       => $lng,
                    'state'           => $estado,
                ],
            );

            $ids[] = (int) DB::table('residential_complexes')
                ->where('name', $nombre)->value('complex_id');
        }

        return $ids;
    }

    /** @return list<array{user_id:int, owner_id:int}> */
    private function propietarios(): array
    {
        $datos = [
            ['Gustavo Charris', 'gustavo.charris', '72145889'],
            ['Yeimy Polo',      'yeimy.polo',      '1045712330'],
            ['Ramiro Bolaño',   'ramiro.bolano',   '8734122'],
            ['Nubia Fontalvo',  'nubia.fontalvo',  '32788401'],
        ];

        $salida = [];

        foreach ($datos as [$nombre, $usuario, $documento]) {
            $userId = $this->usuario([
                'name'    => $nombre,
                'email'   => "{$usuario}@" . self::DOMINIO,
                'rol'     => 2,
                'phone'   => $this->telefono(),
                'address' => 'Barranquilla, Atlántico',
            ]);

            DB::table('owner')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'document_number'   => $documento,
                    'contact_secondary' => $this->telefono(),
                    'notes'             => 'Cuenta de demostración.',
                    'state'             => 1,
                ],
            );

            $salida[] = [
                'user_id'  => $userId,
                'owner_id' => (int) DB::table('owner')->where('user_id', $userId)->value('owner_id'),
            ];
        }

        return $salida;
    }

    /** @return list<int> buyer_id */
    private function compradores(array $conjuntos): array
    {
        $nombres = [
            'Laura Mendoza', 'Jorge Barrios', 'Katherine Ariza', 'Emilio Zúñiga',
            'Daniela Consuegra', 'Wilson Támara', 'Rosa Iriarte', 'Fabián Movilla',
            'Sofía Herrera', 'Alberto Cantillo', 'Mariana Vengoechea', 'Iván Padilla',
        ];

        $ids = [];

        foreach ($nombres as $i => $nombre) {
            $usuario = strtolower(str_replace(' ', '.', $this->sinTildes($nombre)));

            $userId = $this->usuario([
                'name'          => $nombre,
                'email'         => "{$usuario}@" . self::DOMINIO,
                'rol'           => 1,
                'phone'         => $this->telefono(),
                'address'       => $this->direccion(),
                'qualification' => $i % 4 === 0 ? 0 : round(mt_rand(35, 50) / 10, 2),
            ]);

            // Dos de cada tres viven en un conjunto: el resto existe para que la
            // pantalla de conjuntos muestre una penetración creíble y no del 100%.
            $enConjunto = $i % 3 !== 2;

            DB::table('buyer')->updateOrInsert(
                ['user_id' => $userId],
                ['belongs_to_complex' => $enConjunto ? 1 : 0, 'state' => 1],
            );

            $buyerId = (int) DB::table('buyer')->where('user_id', $userId)->value('buyer_id');

            if ($enConjunto) {
                $complejo = $conjuntos[$i % count($conjuntos)];
                DB::table('buyer_complex')->updateOrInsert(
                    ['buyer_id' => $buyerId, 'complex_id' => $complejo],
                    [],
                );
            }

            // Una dirección de entrega por comprador: sin ella el detalle del
            // pedido no tiene a dónde decir que va.
            DB::table('user_address')->updateOrInsert(
                ['user_id' => $userId, 'address' => $this->direccion($i)],
                [
                    'latitude'        => 10.98 + mt_rand(-250, 250) / 10000,
                    'longitude'       => -74.79 + mt_rand(-250, 250) / 10000,
                    'municipality_id' => 16,
                    'state'           => 1,
                ],
            );

            $ids[] = $buyerId;
        }

        return $ids;
    }

    /* ================================================================== */
    /* CATÁLOGO                                                           */

    /** @return list<int> busines_id */
    private function negocios(array $propietarios): array
    {
        $datos = [
            // nombre, tipo (category_business), municipio, propietario, lat, lng, estado
            ['Supermercado La Esquina',   1, 16, 0, 10.9985, -74.8032, 1],
            ['Droguería Vida Sana',       2, 16, 1, 11.0071, -74.8121, 1],
            ['Sabor Costeño Restaurante', 3, 16, 1, 10.9903, -74.7955, 1],
            ['Repuestos El Rodamiento',   4, 17, 2, 10.9166, -74.7702, 1],
            ['Mini Market Villa Sol',     1, 18, 3, 10.8590, -74.7738, 1],
            // Oculto en la app: la pantalla de negocios necesita un caso donde
            // "Publicado / Oculto" signifique algo.
            ['Panadería Doña Nubia',      1, 16, 3, null, null, 0],
        ];

        $ids = [];

        foreach ($datos as $i => [$nombre, $tipo, $municipio, $prop, $lat, $lng, $estado]) {
            $nit = self::NIT . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);

            DB::table('business')->updateOrInsert(
                ['NIT' => $nit],
                [
                    'name'            => $nombre,
                    'razonSocial_DCD' => $nombre . ' S.A.S.',
                    'phone'           => $this->telefono(),
                    'address'         => $this->direccion($i),
                    'description'     => 'Negocio de demostración para probar el panel.',
                    'latitude'        => $lat,
                    'longitude'       => $lng,
                    'municipality_id' => $municipio,
                    'type'            => $tipo,
                    'state'           => $estado,
                    'qualification'   => 0,
                ],
            );

            $id = (int) DB::table('business')->where('NIT', $nit)->value('busines_id');
            $ids[] = $id;

            DB::table('owner_busines')->updateOrInsert(
                ['owner_id' => $propietarios[$prop]['owner_id'], 'busines_id' => $id],
                ['state' => 1],
            );
        }

        return $ids;
    }

    /**
     * Precios y existencias por negocio.
     *
     * Los productos ya están en la base (los trajo el Excel), así que acá solo
     * se decide cuáles vende cada tienda y a cuánto. La categoría manda: una
     * droguería no puede terminar vendiendo repuestos, porque la app clasifica
     * el producto por su categoría y quedaría en un carrusel que no le toca.
     */
    private function catalogo(array $negocios): void
    {
        foreach ($negocios as $i => $negocioId) {
            $tipo = (int) DB::table('business')->where('busines_id', $negocioId)->value('type');

            $categorias = DB::table('category_category_business')
                ->where('business_category_id', $tipo)
                ->pluck('category_id');

            if ($categorias->isEmpty()) {
                continue;
            }

            $productos = DB::table('products')
                ->whereIn('category_id', $categorias)
                ->where('state', 1)
                ->inRandomOrder()
                ->limit(40)
                ->pluck('products_id');

            foreach ($productos as $j => $productoId) {
                // Uno de cada doce agotado: la pantalla de productos avisa de los
                // agotados y sin ninguno ese aviso nunca se ve.
                $existencias = $j % 12 === 0 ? 0 : mt_rand(3, 90);

                DB::table('products_business')->updateOrInsert(
                    ['busines_id' => $negocioId, 'products_id' => $productoId],
                    [
                        'price'  => mt_rand(1200, 48000),
                        'amount' => $existencias,
                    ],
                );
            }
        }
    }

    /* ================================================================== */
    /* OPERACIÓN                                                          */

    /** @return list<array{user_id:int, domiciliary_id:int}> */
    private function domiciliarios(array $negocios): array
    {
        $datos = [
            // nombre, documento, en turno, cuenta activa, acuerdo firmado
            ['Brayan Solano',    '1140882301', 1, 1, true],
            ['Yuranis Castro',   '1045993220', 1, 1, true],
            ['Édinson Manjarrés', '72999104',  1, 1, true],
            ['Milena Orozco',    '1082334519', 0, 1, true],
            ['Óscar Marimón',    '8721456',    1, 1, false],
            ['Tatiana Guzmán',   '1129887654', 0, 1, false],
            // Bloqueado: la lista tiene que poder mostrar que un bloqueo tapa al
            // turno, aunque el turno diga "disponible".
            ['Fredy Escorcia',   '72110455',   1, 0, true],
            ['Karen Villamizar', '1043778812', 1, 1, true],
        ];

        $salida = [];

        foreach ($datos as $i => [$nombre, $documento, $turno, $activo, $acuerdo]) {
            $usuario = strtolower(str_replace(' ', '.', $this->sinTildes($nombre)));

            $userId = $this->usuario([
                'name'          => $nombre,
                'email'         => "{$usuario}@" . self::DOMINIO,
                'rol'           => 3,
                'phone'         => $this->telefono(),
                'address'       => $this->direccion($i),
                'state'         => $activo,
                'qualification' => round(mt_rand(38, 50) / 10, 2),
            ]);

            DB::table('domiciliary')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'available'          => $turno,
                    'document'           => $documento,
                    'contract_signed_at' => $acuerdo
                        ? $this->hoy->copy()->subDays(120 - $i * 9)
                        : null,
                    'contract_city'      => $acuerdo ? 'Barranquilla' : null,
                    'qualification'      => round(mt_rand(38, 50) / 10, 2),
                    'state'              => 1,
                ],
            );

            $domiciliaryId = (int) DB::table('domiciliary')
                ->where('user_id', $userId)->value('domiciliary_id');

            // Cada repartidor atiende dos tiendas: en la vida real no trabajan
            // para una sola, y la columna "Negocios" del listado quedaría vacía.
            foreach ([$negocios[$i % count($negocios)], $negocios[($i + 2) % count($negocios)]] as $negocioId) {
                DB::table('business_domiciliary')->updateOrInsert(
                    ['busines_id' => $negocioId, 'domiciliary_id' => $domiciliaryId],
                    ['state' => 1],
                );
            }

            $salida[] = ['user_id' => $userId, 'domiciliary_id' => $domiciliaryId];
        }

        return $salida;
    }

    /**
     * Pedidos de los últimos 75 días.
     *
     * La distribución no es uniforme a propósito: la gran mayoría entregados —
     * que es lo que permite que las liquidaciones y los reportes tengan algo que
     * sumar— y unos pocos vivos repartidos por los tres estados en curso, más
     * cancelados. Un panel donde todo está entregado no deja probar el flujo, y
     * uno donde todo está en preparación no deja probar la plata.
     *
     * @return list<int> orderSales_id
     */
    private function pedidos(array $negocios, array $domiciliarios, array $compradores): array
    {
        // Los pedidos no tienen clave natural, así que los de demostración se
        // rehacen enteros: son los que cuelgan de los negocios de demostración.
        $viejos = DB::table('orderssales')->whereIn('busines_id', $negocios)->pluck('orderSales_id');

        if ($viejos->isNotEmpty()) {
            DB::table('settlement_items')->whereIn('order_id', $viejos)->delete();
            DB::table('orderssales_detail')->whereIn('orderSales_id', $viejos)->delete();
            DB::table('payments')->whereIn('orderSales_id', $viejos)->delete();
            DB::table('orderssales')->whereIn('orderSales_id', $viejos)->delete();
        }

        $ids = [];

        for ($i = 0; $i < 90; $i++) {
            $negocioId = $negocios[$i % count($negocios)];
            $comprador = $compradores[$i % count($compradores)];
            $repartidor = $domiciliarios[$i % count($domiciliarios)];

            $diasAtras = intdiv($i * 75, 90);
            $fecha = $this->hoy->copy()->subDays($diasAtras)->setTime(mt_rand(8, 20), mt_rand(0, 59));

            // Los últimos días son los que pueden estar en curso; lo de hace un
            // mes ya se resolvió de una forma o de otra.
            if ($diasAtras <= 2) {
                $estado = [1, 2, 3][$i % 3];
            } elseif ($i % 17 === 0) {
                $estado = 5; // cancelado
            } else {
                $estado = 4; // entregado
            }

            $subtotal  = mt_rand(18000, 240000);
            $domicilio = [4000, 5000, 6000, 7000][$i % 4];
            $descuento = $i % 9 === 0 ? (int) round($subtotal * 0.1) : 0;
            $comision  = (int) round($domicilio * 0.25);

            $pagado = in_array($estado, [4], true) || $i % 3 === 0;

            $orderId = DB::table('orderssales')->insertGetId([
                'buyer_id'        => $comprador,
                'busines_id'      => $negocioId,
                'domiciliary_id'  => $estado >= 3 ? $repartidor['domiciliary_id'] : null,
                'methods_id'      => [1, 2, 5, 6][$i % 4],
                'forms_id'        => [1, 2][$i % 2],
                'subtotal'        => $subtotal,
                'domicilio'       => $domicilio,
                'discount'        => $descuento,
                'domiciliary_fee' => $comision,
                'total'           => $subtotal + $domicilio - $descuento,
                'currency'        => 'COP',
                'sale_date'       => $fecha,
                'state'           => $estado,
                'payment_state'   => $estado === 5 ? 'cancelled' : ($pagado ? 'approved' : 'pending'),
                'dispatched_at'   => $estado >= 3 ? $fecha->copy()->addMinutes(mt_rand(12, 40)) : null,
                'delivery_date'   => $estado === 4 ? $fecha->copy()->addMinutes(mt_rand(35, 95)) : null,
                'created_at'      => $fecha,
                'updated_at'      => $fecha,
            ], 'orderSales_id');

            $this->detalleDelPedido((int) $orderId, $negocioId, $subtotal);

            $ids[] = (int) $orderId;
        }

        return $ids;
    }

    /** Renglones del pedido, cuadrados contra su subtotal. */
    private function detalleDelPedido(int $orderId, int $negocioId, int $subtotal): void
    {
        $ofertas = DB::table('products_business')
            ->where('busines_id', $negocioId)
            ->inRandomOrder()
            ->limit(mt_rand(2, 5))
            ->get(['products_id', 'price']);

        if ($ofertas->isEmpty()) {
            return;
        }

        // El último renglón absorbe la diferencia para que la suma de los
        // renglones dé exactamente el subtotal del pedido: un detalle que no
        // cuadra con su cabecera es la clase de dato que hace desconfiar de
        // toda la pantalla.
        $restante = $subtotal;
        $ultimo   = $ofertas->count() - 1;

        foreach ($ofertas as $i => $oferta) {
            if ($i === $ultimo) {
                $precio = max(100, $restante);
                $cantidad = 1;
            } else {
                $cantidad = mt_rand(1, 3);
                $precio   = min((int) $oferta->price, intdiv(max(100, $restante - 100), $cantidad + 1));
                $precio   = max(100, $precio);
                $restante -= $precio * $cantidad;
            }

            DB::table('orderssales_detail')->insert([
                'orderSales_id' => $orderId,
                'product_id'    => $oferta->products_id,
                'amount'        => $cantidad,
                'unit_price'    => $precio,
            ]);
        }
    }

    private function pagos(array $pedidos): void
    {
        $cobrables = DB::table('orderssales')
            ->whereIn('orderSales_id', $pedidos)
            ->where('payment_state', '!=', 'pending')
            ->get();

        foreach ($cobrables as $i => $p) {
            $rechazado = $i % 23 === 0 && $p->payment_state !== 'cancelled';

            $estado = match (true) {
                $p->payment_state === 'cancelled' => 'cancelled',
                $rechazado                        => 'rejected',
                default                           => 'approved',
            };

            DB::table('payments')->insert([
                'orderSales_id'       => $p->orderSales_id,
                'methods_id'          => $p->methods_id,
                'forms_id'            => $p->forms_id,
                // Los nombres que escribe la aplicación de verdad: el panel
                // separa efectivo de pasarela por este campo, y con 'efectivo'
                // en vez de 'cash' los 23 pagos en efectivo se contaban como
                // pasarela. Unos datos de prueba que no usan los valores reales
                // prueban otra cosa.
                'provider'            => (int) $p->methods_id === 1 ? 'cash' : 'bold',
                'provider_payment_id' => 'demo-' . $p->orderSales_id,
                'amount'              => $p->total,
                'subtotal'            => $p->subtotal,
                'total'               => $p->total,
                'domicilio'           => $p->domicilio,
                'domiciliary_fee'     => $p->domiciliary_fee,
                'valor_promocion'     => $p->discount,
                // `payment_status` es el código numérico heredado y `status` el
                // texto que lee el panel: los dos tienen que contar lo mismo.
                'payment_status'      => $estado === 'approved' ? 1 : 0,
                'status'              => $estado,
                'payment_date'        => $p->sale_date,
                'state'               => 1,
                'created_at'          => $p->sale_date,
                'updated_at'          => $p->sale_date,
            ]);
        }
    }

    /* ================================================================== */
    /* COMUNIDAD: RESEÑAS Y CONVERSACIONES                                */

    private function resenas(array $negocios, array $domiciliarios, array $compradores): void
    {
        $comentarios = [
            5 => ['Todo llegó completo y bien empacado.', 'Excelente atención, repito.', 'Muy rápido, mejor de lo que esperaba.'],
            4 => ['Buen servicio, aunque tardó un poco.', 'Todo bien, solo faltó la bolsa aparte.'],
            3 => ['Normal. Ni bien ni mal.', 'El pedido llegó pero incompleto.'],
            2 => ['Llegó tarde y sin avisar.', 'Los productos venían mal empacados.'],
            1 => ['Nunca llegó y no contestaron.', 'Pésimo, pedí reembolso.'],
        ];

        DB::table('business_reviews')->whereIn('busines_id', $negocios)->delete();
        DB::table('domiciliary_reviews')
            ->whereIn('domiciliary_id', array_column($domiciliarios, 'domiciliary_id'))->delete();

        foreach ($negocios as $i => $negocioId) {
            for ($j = 0; $j < 6; $j++) {
                // La mayoría positivas con alguna negativa suelta: es la forma
                // real de una distribución de reseñas, y el filtro de negativas
                // tiene que encontrar poco, no la mitad.
                $puntaje = [5, 5, 4, 5, 3, 1][$j];

                DB::table('business_reviews')->insert([
                    'busines_id'    => $negocioId,
                    'buyer_id'      => $compradores[($i + $j) % count($compradores)],
                    'qualification' => $puntaje,
                    'comment'       => $comentarios[$puntaje][$j % count($comentarios[$puntaje])],
                    'state'         => 1,
                    'created_at'    => $this->hoy->copy()->subDays(3 + $j * 5 + $i),
                    'updated_at'    => $this->hoy->copy()->subDays(3 + $j * 5 + $i),
                ]);
            }
        }

        foreach ($domiciliarios as $i => $d) {
            for ($j = 0; $j < 4; $j++) {
                $puntaje = [5, 4, 5, 2][$j];

                DB::table('domiciliary_reviews')->insert([
                    'domiciliary_id' => $d['domiciliary_id'],
                    'buyer_id'       => $compradores[($i + $j) % count($compradores)],
                    'qualification'  => $puntaje,
                    'comment'        => $comentarios[$puntaje][$j % count($comentarios[$puntaje])],
                    'state'          => 1,
                    'created_at'     => $this->hoy->copy()->subDays(2 + $j * 7 + $i),
                    'updated_at'     => $this->hoy->copy()->subDays(2 + $j * 7 + $i),
                ]);
            }
        }

        // La calificación que muestra cada ficha es el promedio de sus reseñas:
        // dejarla en cero mientras abajo hay seis reseñas se lee como un error.
        DB::statement('
            UPDATE business b
               SET b.qualification = COALESCE((
                     SELECT ROUND(AVG(r.qualification), 2)
                       FROM business_reviews r
                      WHERE r.busines_id = b.busines_id
                   ), 0)
             WHERE b.busines_id IN (' . implode(',', $negocios) . ')
        ');

        $idsDomi = implode(',', array_column($domiciliarios, 'domiciliary_id'));
        DB::statement("
            UPDATE domiciliary d
               SET d.qualification = COALESCE((
                     SELECT ROUND(AVG(r.qualification), 2)
                       FROM domiciliary_reviews r
                      WHERE r.domiciliary_id = d.domiciliary_id
                   ), 0)
             WHERE d.domiciliary_id IN ({$idsDomi})
        ");
    }

    private function conversaciones(array $negocios, array $domiciliarios, array $compradores): void
    {
        $guiones = [
            [
                ['comprador', '¿Ya salió mi pedido?'],
                ['domiciliario', 'Sí señora, voy llegando al conjunto en 10 minutos.'],
                ['comprador', 'Perfecto, aviso en portería.'],
            ],
            [
                ['comprador', 'Buenas, me llegó un producto vencido.'],
                ['tendero', 'Qué pena, ¿me manda la foto? Se lo reponemos hoy mismo.'],
                ['comprador', 'Listo, ya la mando.'],
            ],
            [
                ['comprador', '¿Tienen leche deslactosada?'],
                ['tendero', 'Sí, de dos marcas. ¿Se la agrego al pedido?'],
            ],
            [
                ['domiciliario', 'La dirección no aparece, ¿es la torre 3?'],
                ['comprador', 'Torre 3, apartamento 502.'],
                ['domiciliario', 'Gracias, subo ya.'],
            ],
        ];

        foreach ($guiones as $i => $guion) {
            $compradorUserId = (int) DB::table('buyer')
                ->where('buyer_id', $compradores[$i % count($compradores)])
                ->value('user_id');

            $tenderoUserId = (int) DB::table('owner_busines as ob')
                ->join('owner as o', 'o.owner_id', '=', 'ob.owner_id')
                ->where('ob.busines_id', $negocios[$i % count($negocios)])
                ->value('o.user_id');

            $domiciliarioUserId = $domiciliarios[$i % count($domiciliarios)]['user_id'];

            // Sin clave natural: se rehacen los chats de esta gente.
            $previos = DB::table('chat_participants')
                ->where('user_id', $compradorUserId)
                ->pluck('chat_id');

            if ($previos->isNotEmpty()) {
                DB::table('messages')->whereIn('chat_id', $previos)->delete();
                DB::table('chat_participants')->whereIn('chat_id', $previos)->delete();
                DB::table('chats')->whereIn('chat_id', $previos)->delete();
            }

            $chatId = DB::table('chats')->insertGetId([
                'type'       => 'private',
                'created_at' => $this->hoy->copy()->subDays($i + 1),
            ], 'chat_id');

            $porRol = [
                'comprador'    => [$compradorUserId, 1],
                'tendero'      => [$tenderoUserId, 2],
                'domiciliario' => [$domiciliarioUserId, 3],
            ];

            foreach (array_unique(array_column($guion, 0)) as $quien) {
                [$userId, $rol] = $porRol[$quien];

                DB::table('chat_participants')->insert([
                    'chat_id'   => $chatId,
                    'user_id'   => $userId,
                    'role_id'   => $rol,
                    'joined_at' => $this->hoy->copy()->subDays($i + 1),
                ]);
            }

            foreach ($guion as $j => [$quien, $texto]) {
                [$userId, $rol] = $porRol[$quien];

                DB::table('messages')->insert([
                    'chat_id'    => $chatId,
                    'user_id'    => $userId,
                    'role_id'    => $rol,
                    'content'    => $texto,
                    'created_at' => $this->hoy->copy()->subDays($i + 1)->addMinutes($j * 3),
                ]);
            }
        }
    }

    /* ================================================================== */
    /* MARKETING                                                          */

    private function marketing(array $negocios): void
    {
        $anunciantes = [
            ['Postobón Región Caribe', null, 'Mauricio Lara', 'mauricio.lara@postobon.demo', '9001234561'],
            ['Alpina Costa',           null, 'Diana Pineda',  'diana.pineda@alpina.demo',    '9001234562'],
            // Un anunciante que es un negocio de la plataforma: la mezcla de
            // marcas externas y tiendas propias fue la decisión de diseño, y sin
            // un caso de cada uno no se ve.
            ['Supermercado La Esquina', $negocios[0], 'Gustavo Charris', 'gustavo.charris@' . self::DOMINIO, self::NIT . '001'],
            ['Droguería Vida Sana',     $negocios[1], 'Yeimy Polo',      'yeimy.polo@' . self::DOMINIO,      self::NIT . '002'],
        ];

        $idsAnunciante = [];

        foreach ($anunciantes as [$nombre, $negocioId, $contacto, $correo, $nit]) {
            DB::table('advertisers')->updateOrInsert(
                ['name' => $nombre],
                [
                    'business_id'   => $negocioId,
                    'contact_name'  => $contacto,
                    'contact_email' => $correo,
                    'contact_phone' => $this->telefono(),
                    'tax_id'        => $nit,
                    'state'         => 1,
                    'created_at'    => $this->hoy->copy()->subDays(90),
                    'updated_at'    => now(),
                ],
            );

            $idsAnunciante[] = (int) DB::table('advertisers')->where('name', $nombre)->value('id');
        }

        $campanas = [
            // anunciante, nombre, objetivo, arranca hace, dura, presupuesto, estado
            [0, 'Verano Postobón 2026',      'awareness',  20,  40, 4500000, 1],
            [1, 'Alpina — Desayunos',        'traffic',    10,  30, 2800000, 1],
            [2, 'La Esquina — Mercado del mes', 'conversion', 5, 25, 900000, 1],
            [3, 'Vida Sana — Cuidado diario', 'traffic',   -5,  30, 600000, 0],  // arranca en 5 días
            [0, 'Postobón — Fin de año',     'awareness', 200, 30, 3000000, 1],  // terminada
        ];

        $idsCampana = [];

        foreach ($campanas as [$anunciante, $nombre, $objetivo, $haceDias, $dura, $presupuesto, $estado]) {
            $inicio = $this->hoy->copy()->subDays($haceDias);

            DB::table('ad_campaigns')->updateOrInsert(
                ['advertiser_id' => $idsAnunciante[$anunciante], 'name' => $nombre],
                [
                    'objective'  => $objetivo,
                    'starts_at'  => $inicio->toDateString(),
                    'ends_at'    => $inicio->copy()->addDays($dura)->toDateString(),
                    'budget'     => $presupuesto,
                    'state'      => $estado,
                    'created_at' => $inicio,
                    'updated_at' => now(),
                ],
            );

            $idsCampana[] = (int) DB::table('ad_campaigns')
                ->where('advertiser_id', $idsAnunciante[$anunciante])
                ->where('name', $nombre)
                ->value('id');
        }

        $banners = [
            // campaña, título, subtítulo, ubicación, plataforma, prioridad,
            // impresiones diarias aproximadas, de cada cuántas se hace clic, estado
            [0, 'Refresca tu verano',      'Bebidas heladas a domicilio en 30 minutos', 'home_hero',      'both', 10, 90, 28, 1],
            [0, 'Postobón 3x2',            'Solo esta semana',                          'home_strip',     'app',   8, 55, 34, 1],
            [1, 'Desayuna completo',       'Lácteos frescos todos los días',            'home_hero',      'both',  9, 70, 30, 1],
            [1, 'Alpina en tu tienda',     null,                                        'listing_inline', 'app',   5, 35, 60, 1],
            [2, 'Mercado del mes',         'Ahorra hasta un 20% en tu mercado',         'home_hero',      'both',  7, 80, 20, 1],
            [2, 'Domicilio gratis',        'En compras desde $80.000',                  'home_strip',     'both',  6, 28, 18, 1],
            // Todavía sin arrancar: su campaña empieza dentro de cinco días.
            [3, 'Cuidado diario',          'Tu droguería de confianza',                 'splash',         'app',   4,  0,  0, 0],
            // Apagado a mano: hace falta un banner inactivo para ver que el
            // filtro de estado separa "no está corriendo" de "ya venció".
            [4, 'Fin de año Postobón',     'Campaña cerrada',                           'web_home',       'web',   1,  0,  0, 0],
        ];

        $idsBanner = [];

        foreach ($banners as [$campana, $titulo, $subtitulo, $ubicacion, $plataforma, $prioridad, $porDia, $unoDeCada, $estado]) {
            DB::table('banners')->updateOrInsert(
                ['campaign_id' => $idsCampana[$campana], 'title' => $titulo],
                [
                    'subtitle'   => $subtitulo,
                    'placement'  => $ubicacion,
                    'platform'   => $plataforma,
                    'link_type'  => 'none',
                    'priority'   => $prioridad,
                    'state'      => $estado,
                    'created_at' => $this->hoy->copy()->subDays(30),
                    'updated_at' => now(),
                ],
            );

            $idsBanner[] = [
                (int) DB::table('banners')
                    ->where('campaign_id', $idsCampana[$campana])
                    ->where('title', $titulo)
                    ->value('id'),
                $porDia,
                $unoDeCada,
            ];
        }

        $this->eventosDeBanners($idsBanner);

        $cupones = [
            // código, descripción, tipo, valor, tope, mínimo, máximos, usados, negocio, arranca hace, dura, estado
            ['BIENVENIDO10', 'Primer pedido con 10% de descuento', 'percent', 10, 15000,  20000, 500, 213, null, 60, 300, 1],
            ['DOMIGRATIS',   'Domicilio gratis desde $80.000',     'fixed',  6000,  null,  80000, 300,  97, null, 20,  40, 1],
            ['MERCADO20',    '20% en tu mercado de La Esquina',    'percent', 20, 40000, 100000, 100,  38, 0,    10,  30, 1],
            // Agotado: el estado "sin usos disponibles" solo se puede probar si
            // hay uno que llegó a su tope.
            ['FLASH50',      'Promoción relámpago agotada',        'percent', 50, 20000,  30000,  50,  50, null, 15,  10, 1],
            // Vencido.
            ['NAVIDAD25',    'Campaña de diciembre',               'percent', 25, 30000,  50000, 400, 356, null, 240, 30, 1],
            // Apagado a mano.
            ['PRUEBA00',     'Cupón desactivado',                  'fixed',  3000,  null,      0,  10,   0, null,  5,  30, 0],
        ];

        foreach ($cupones as [$codigo, $descripcion, $tipo, $valor, $tope, $minimo, $maximos, $usados, $negocio, $haceDias, $dura, $estado]) {
            $inicio = $this->hoy->copy()->subDays($haceDias);

            DB::table('coupons')->updateOrInsert(
                ['code' => $codigo],
                [
                    'description'       => $descripcion,
                    'type'              => $tipo,
                    'value'             => $valor,
                    'max_discount'      => $tope,
                    'min_order'         => $minimo,
                    'max_uses'          => $maximos,
                    'max_uses_per_user' => 1,
                    'uses_count'        => $usados,
                    'business_id'       => $negocio === null ? null : $negocios[$negocio],
                    'starts_at'         => $inicio->toDateString(),
                    'ends_at'           => $inicio->copy()->addDays($dura)->toDateString(),
                    'state'             => $estado,
                    'created_at'        => $inicio,
                    'updated_at'        => now(),
                ],
            );
        }

        $destacados = [
            [0, 'home_top',     10, 1200000, 30420, 2180,  30, 1],
            [1, 'home_top',      8,  900000, 21100, 1340,  20, 1],
            [2, 'category_top',  6,  450000, 12800,  760,  15, 1],
            // Vencido: el listado tiene que distinguir "pagó y está corriendo"
            // de "pagó, ya se acabó y hay que renovarle".
            [3, 'search_top',    4,  300000,  8900,  410, 120, 1],
        ];

        foreach ($destacados as [$negocio, $ubicacion, $prioridad, $pagado, $impresiones, $clics, $haceDias, $estado]) {
            $inicio = $this->hoy->copy()->subDays($haceDias);

            DB::table('featured_businesses')->updateOrInsert(
                ['business_id' => $negocios[$negocio], 'placement' => $ubicacion],
                [
                    'priority'          => $prioridad,
                    'starts_at'         => $inicio->toDateString(),
                    'ends_at'           => $inicio->copy()->addDays(60)->toDateString(),
                    'paid_amount'       => $pagado,
                    'impressions_count' => $impresiones,
                    'clicks_count'      => $clics,
                    'state'             => $estado,
                    'created_at'        => $inicio,
                    'updated_at'        => now(),
                ],
            );
        }

        $push = [
            // título, cuerpo, programada en, enviada hace, destinatarios, estado
            ['Tu mercado en 30 minutos', 'Pide hoy y recibe antes de la cena. Domicilio gratis desde $80.000.', null, 6,  4820, 2],
            ['Nuevos negocios cerca',    'Ya tienes tres tiendas nuevas en tu zona. Míralas en la app.',        null, 20, 3960, 2],
            ['Fin de semana con 20%',    'Usa el cupón MERCADO20 en tu próximo pedido.',                        2, null,    0, 1],
            ['Borrador sin enviar',      'Mensaje en preparación para la campaña de fin de mes.',            null, null,    0, 0],
        ];

        foreach ($push as [$titulo, $cuerpo, $programadaEn, $enviadaHace, $destinatarios, $estado]) {
            DB::table('push_campaigns')->updateOrInsert(
                ['title' => $titulo],
                [
                    'body'             => $cuerpo,
                    'link_type'        => 'none',
                    'scheduled_at'     => $programadaEn === null ? null : $this->hoy->copy()->addDays($programadaEn)->setTime(9, 0),
                    'sent_at'          => $enviadaHace === null ? null : $this->hoy->copy()->subDays($enviadaHace)->setTime(10, 30),
                    'recipients_count' => $destinatarios,
                    'state'            => $estado,
                    'created_at'       => $this->hoy->copy()->subDays(($enviadaHace ?? 1) + 2),
                    'updated_at'       => now(),
                ],
            );
        }
    }

    /**
     * Impresiones y clics día a día de los últimos 30.
     *
     * Se generan los EVENTOS y después se recalculan los contadores a partir de
     * ellos, no al revés. El resumen de marketing dibuja la curva leyendo
     * `banner_events`, así que poner un contador acumulado a mano dejaba la
     * pantalla diciendo "18.420 impresiones" arriba y una gráfica plana en cero
     * debajo: dos cifras que se contradicen en el mismo pantallazo.
     *
     * @param list<array{0:int,1:int,2:int}> $banners [id, impresiones/día, clics 1 de cada N]
     */
    private function eventosDeBanners(array $banners): void
    {
        $ids = array_column($banners, 0);

        DB::table('banner_events')->whereIn('banner_id', $ids)->delete();

        $lote = [];

        foreach ($banners as [$bannerId, $porDia, $unoDeCada]) {
            if ($porDia === 0) {
                continue;
            }

            for ($dia = 29; $dia >= 0; $dia--) {
                $fecha = $this->hoy->copy()->subDays($dia);

                // El fin de semana se pide más domicilio, y una curva
                // perfectamente plana no se parece a ningún dato real.
                $factor = $fecha->isWeekend() ? 1.35 : 1.0;
                $cuantas = (int) round($porDia * $factor * mt_rand(80, 120) / 100);

                for ($i = 0; $i < $cuantas; $i++) {
                    $lote[] = [
                        'banner_id'  => $bannerId,
                        'type'       => $i % $unoDeCada === 0 ? 'click' : 'impression',
                        'platform'   => ['app', 'web'][$i % 2],
                        'day'        => $fecha->toDateString(),
                        'created_at' => $fecha->copy()->setTime(mt_rand(7, 22), mt_rand(0, 59)),
                    ];

                    if (count($lote) >= 1000) {
                        DB::table('banner_events')->insert($lote);
                        $lote = [];
                    }
                }
            }
        }

        if ($lote !== []) {
            DB::table('banner_events')->insert($lote);
        }

        // Los contadores del banner pasan a ser el recuento de sus eventos.
        DB::statement('
            UPDATE banners b
               SET b.impressions_count = COALESCE((
                     SELECT COUNT(*) FROM banner_events e
                      WHERE e.banner_id = b.id AND e.type = "impression"
                   ), 0),
                   b.clicks_count = COALESCE((
                     SELECT COUNT(*) FROM banner_events e
                      WHERE e.banner_id = b.id AND e.type = "click"
                   ), 0)
             WHERE b.id IN (' . implode(',', $ids) . ')
        ');
    }

    /* ================================================================== */
    /* SST                                                                */

    /**
     * Papelería de los repartidores.
     *
     * La gracia está en el reparto de vencimientos: unos vigentes, otros a punto
     * de vencer, otros vencidos y dos repartidores a los que les falta un
     * documento obligatorio. Esa última fila es la que la pantalla existe para
     * encontrar, y con todo en regla nunca aparecería.
     */
    private function sst(array $domiciliarios): void
    {
        $ids = array_column($domiciliarios, 'domiciliary_id');

        DB::table('domiciliary_documents')->whereIn('domiciliary_id', $ids)->delete();
        DB::table('safety_incidents')->whereIn('domiciliary_id', $ids)->delete();

        // Días que le faltan a cada documento para vencer, por repartidor.
        // `null` = no lo tiene registrado.
        $vencimientos = [
            0 => ['licencia' => 400, 'soat' => 180, 'tecnomecanica' => 210, 'arl' => 300, 'eps' => 300],
            1 => ['licencia' => 620, 'soat' => 22,  'tecnomecanica' => 95,  'arl' => 260, 'eps' => 260],
            2 => ['licencia' => 90,  'soat' => -12, 'tecnomecanica' => 40,  'arl' => 150, 'eps' => 150],
            3 => ['licencia' => 500, 'soat' => 340, 'tecnomecanica' => 12,  'arl' => 200, 'eps' => 200],
            4 => ['licencia' => 260, 'soat' => 150, 'tecnomecanica' => 170, 'arl' => null, 'eps' => 190],
            5 => ['licencia' => -45, 'soat' => 60,  'tecnomecanica' => 80,  'arl' => 110, 'eps' => 110],
            6 => ['licencia' => 700, 'soat' => 280, 'tecnomecanica' => 300, 'arl' => 320, 'eps' => null],
            7 => ['licencia' => 380, 'soat' => 26,  'tecnomecanica' => 28,  'arl' => 240, 'eps' => 240],
        ];

        foreach ($domiciliarios as $i => $d) {
            foreach ($vencimientos[$i] as $tipo => $dias) {
                if ($dias === null) {
                    continue;
                }

                $vence = $this->hoy->copy()->addDays($dias);

                DB::table('domiciliary_documents')->insert([
                    'domiciliary_id' => $d['domiciliary_id'],
                    'type'           => $tipo,
                    'number'         => strtoupper(substr($tipo, 0, 3)) . '-' . (100000 + $i * 137 + strlen($tipo)),
                    'issued_at'      => $vence->copy()->subYear()->toDateString(),
                    'expires_at'     => $vence->toDateString(),
                    'state'          => 1,
                    'created_at'     => $this->hoy->copy()->subDays(60),
                    'updated_at'     => now(),
                ]);
            }
        }

        $incidentes = [
            // repartidor, hace días, tipo, gravedad, lesiones, incapacidad, sitio, descripción, estado
            [2, 5,  'accidente_transito', 'moderado', 1, 3,  'Calle 84 con Carrera 51',
             'Colisión con un vehículo particular que giró sin señalizar. La moto quedó con el rin delantero doblado.', 1],
            [5, 12, 'caida',              'leve',     1, 0,  'Conjunto Villa Carolina, rampa de acceso',
             'Resbaló en la rampa mojada mientras subía el pedido. Solo raspaduras.', 2],
            [0, 20, 'robo',               'grave',    0, 0,  'Carrera 38 con Calle 74',
             'Dos sujetos en moto lo interceptaron y se llevaron el celular y el pedido.', 2],
            [7, 3,  'falla_vehiculo',     'leve',     0, 0,  'Vía 40',
             'Se quedó sin frenos traseros a mitad de ruta. Alcanzó a detenerse sin golpe.', 0],
            [1, 8,  'condicion_insegura', 'leve',     0, 0,  'Bodega de Supermercado La Esquina',
             'El pasillo de recogida estaba obstruido con cajas y sin iluminación.', 0],
            [4, 35, 'agresion',           'moderado', 0, 1,  'Barrio El Bosque',
             'Un cliente lo agredió verbalmente y le lanzó el pedido tras un retraso de 15 minutos.', 2],
        ];

        foreach ($incidentes as [$quien, $haceDias, $tipo, $gravedad, $lesiones, $incapacidad, $sitio, $descripcion, $estado]) {
            $cuando = $this->hoy->copy()->subDays($haceDias)->setTime(mt_rand(9, 19), mt_rand(0, 59));

            DB::table('safety_incidents')->insert([
                'domiciliary_id' => $domiciliarios[$quien]['domiciliary_id'],
                'occurred_at'    => $cuando,
                'type'           => $tipo,
                'severity'       => $gravedad,
                'had_injuries'   => $lesiones,
                'days_off'       => $incapacidad,
                'location'       => $sitio,
                'description'    => $descripcion,
                // Un incidente cerrado sin acciones no se puede cerrar: el
                // servidor lo rechaza, y con razón — cerrar sin decir qué se
                // hizo es archivar el problema, no resolverlo.
                'actions'        => $estado === 2
                    ? 'Se cubrió la incapacidad, se reforzó la charla de seguridad vial y se revisó el estado del vehículo.'
                    : null,
                'closed_at'      => $estado === 2 ? $cuando->copy()->addDays(4) : null,
                'state'          => $estado,
                'created_at'     => $cuando,
                'updated_at'     => now(),
            ]);
        }
    }

    /* ================================================================== */
    /* CALIDAD: PQRS                                                      */

    private function calidad(
        array $compradores,
        array $negocios,
        array $domiciliarios,
        array $pedidos,
        array $areas,
    ): void {
        $casos = [
            // tipo, canal, prioridad, hace días, asunto, descripción, estado
            ['reclamo',    'app',      'alta',  1,
             'Pedido cobrado y no entregado',
             'El cobro salió aprobado pero el pedido nunca llegó y el domiciliario no contesta.', 0],
            ['queja',      'whatsapp', 'alta',  2,
             'Domiciliario grosero',
             'El repartidor discutió con el portero y se fue sin entregar.', 1],
            ['reclamo',    'llamada',  'media', 4,
             'Producto vencido',
             'La leche venía con fecha de hace dos semanas.', 1],
            ['peticion',   'app',      'baja',  6,
             'Ampliar cobertura a Malambo',
             'Solicito que habiliten entregas en el barrio Mesolandia.', 0],
            ['queja',      'app',      'media', 9,
             'Cobro doble del domicilio',
             'Me cobraron dos veces el valor del domicilio en el mismo pedido.', 2],
            ['sugerencia', 'correo',   'baja',  12,
             'Poder programar el pedido',
             'Sería útil dejar el pedido listo la noche anterior y que llegue en la mañana.', 2],
            ['felicitacion', 'app',    'baja',  14,
             'Excelente servicio',
             'El domiciliario esperó a que bajara con la silla de ruedas. Muy amable.', 3],
            ['reclamo',    'panel',    'alta',  18,
             'Pedido incompleto y sin respuesta',
             'Faltaron tres productos y llevo una semana sin respuesta del negocio.', 2],
            ['queja',      'whatsapp', 'media', 21,
             'Demora de más de dos horas',
             'El pedido tardó 2 horas y 15 minutos en un trayecto de 10 minutos.', 3],
            ['peticion',   'app',      'media', 25,
             'Factura electrónica',
             'Necesito la factura a nombre de mi empresa para el pedido del 20.', 2],
            // Vencida a propósito: con prioridad alta el plazo es de 2 días y
            // esta lleva 8 abierta. La pantalla existe para que eso salte.
            ['reclamo',    'llamada',  'alta',  8,
             'Reembolso pendiente hace una semana',
             'Cancelaron mi pedido y todavía no me devuelven el dinero.', 1],
            ['queja',      'app',      'baja',  30,
             'La app se cierra al pagar',
             'Al confirmar el pago la aplicación se cierra sola en un teléfono Android.', 3],
        ];

        DB::table('pqrs_notes')->whereIn(
            'pqrs_id',
            DB::table('pqrs')->where('code', 'like', 'DEMO-%')->pluck('id'),
        )->delete();
        DB::table('pqrs')->where('code', 'like', 'DEMO-%')->delete();

        $plazos = ['alta' => 2, 'media' => 5, 'baja' => 10];

        foreach ($casos as $i => [$tipo, $canal, $prioridad, $haceDias, $asunto, $descripcion, $estado]) {
            $creada = $this->hoy->copy()->subDays($haceDias)->setTime(mt_rand(8, 18), mt_rand(0, 59));

            $compradorUserId = (int) DB::table('buyer')
                ->where('buyer_id', $compradores[$i % count($compradores)])
                ->value('user_id');

            $resuelta = in_array($estado, [2, 3], true);

            DB::table('pqrs')->insert([
                'code'           => 'DEMO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'type'           => $tipo,
                'channel'        => $canal,
                'priority'       => $prioridad,
                'user_id'        => $compradorUserId,
                'order_id'       => $pedidos[$i % count($pedidos)],
                'business_id'    => $negocios[$i % count($negocios)],
                'domiciliary_id' => $i % 3 === 0
                    ? $domiciliarios[$i % count($domiciliarios)]['domiciliary_id']
                    : null,
                'subject'        => $asunto,
                'description'    => $descripcion,
                'state'          => $estado,
                'assigned_to'    => $areas['calidad'] ?? null,
                'due_at'         => $creada->copy()->addDays($plazos[$prioridad]),
                'resolved_at'    => $resuelta ? $creada->copy()->addDays(1) : null,
                'resolution'     => $resuelta
                    ? 'Se contactó al usuario, se verificó con el negocio y se aplicó la compensación acordada.'
                    : null,
                'created_at'     => $creada,
                'updated_at'     => now(),
            ]);
        }
    }

    /* ================================================================== */
    /* CONTABILIDAD                                                       */

    /**
     * Cortes de cuentas del mes pasado.
     *
     * Se generan con el servicio real y no insertando filas a mano: si la
     * aritmética del reparto cambia, estos datos cambian con ella. Unos datos
     * de prueba que no pasan por el mismo código que la aplicación dejan de
     * probar justo lo que había que probar.
     */
    private function liquidaciones(array $negocios, array $domiciliarios, array $areas): void
    {
        DB::table('settlements')
            ->whereIn('business_id', $negocios)
            ->orWhereIn('domiciliary_id', array_column($domiciliarios, 'domiciliary_id'))
            ->delete();

        $servicio = app(LiquidacionService::class);

        $desde = $this->hoy->copy()->subMonthNoOverflow()->startOfMonth();
        $hasta = $desde->copy()->endOfMonth();
        $quien = $areas['contabilidad'] ?? null;

        $estados = [
            \App\Models\Operacion\Settlement::PAGADA,
            \App\Models\Operacion\Settlement::APROBADA,
            \App\Models\Operacion\Settlement::BORRADOR,
        ];

        $n = 0;

        foreach (array_slice($negocios, 0, 4) as $negocioId) {
            try {
                $liq = $servicio->generar('business', $negocioId, $desde->toDateString(), $hasta->toDateString(), $quien);
            } catch (RuntimeException) {
                continue; // ese negocio no vendió nada el mes pasado
            }

            $this->avanzarLiquidacion($liq, $estados[$n % 3]);
            $n++;
        }

        foreach (array_slice($domiciliarios, 0, 4) as $d) {
            try {
                $liq = $servicio->generar(
                    'domiciliary',
                    $d['domiciliary_id'],
                    $desde->toDateString(),
                    $hasta->toDateString(),
                    $quien,
                );
            } catch (RuntimeException) {
                continue;
            }

            $this->avanzarLiquidacion($liq, $estados[$n % 3]);
            $n++;
        }
    }

    private function avanzarLiquidacion($liq, int $estado): void
    {
        $cambios = ['state' => $estado];

        if ($estado >= \App\Models\Operacion\Settlement::APROBADA) {
            $cambios['approved_at'] = $this->hoy->copy()->subDays(6);
        }

        if ($estado === \App\Models\Operacion\Settlement::PAGADA) {
            $cambios['paid_at'] = $this->hoy->copy()->subDays(3);
            $cambios['payment_reference'] = 'TRF-' . str_pad((string) $liq->id, 6, '0', STR_PAD_LEFT);
        }

        $liq->update($cambios);
    }

    /* ================================================================== */
    /* UTILIDADES                                                         */

    /**
     * Alta o actualización de una persona, sin tocarle la contraseña si ya
     * existía: quien esté probando con una cuenta abierta no debería perder la
     * sesión porque alguien reejecutó el seeder.
     */
    private function usuario(array $datos): int
    {
        $existente = DB::table('user')->where('email', $datos['email'])->first();

        $comunes = [
            'name'              => $datos['name'],
            'phone'             => $datos['phone'] ?? null,
            'address'           => $datos['address'] ?? null,
            'rol'               => $datos['rol'],
            'area_id'           => $datos['area_id'] ?? null,
            'access_level'      => $datos['access_level'] ?? Area::NIVEL_CONSULTA,
            'qualification'     => $datos['qualification'] ?? 0,
            'state'             => $datos['state'] ?? 1,
            'email_verified_at' => now(),
        ];

        if ($existente) {
            DB::table('user')->where('user_id', $existente->user_id)->update($comunes);

            return (int) $existente->user_id;
        }

        return (int) DB::table('user')->insertGetId(
            $comunes + [
                'email'      => $datos['email'],
                'password'   => Hash::make(self::CLAVE),
                // La fecha la pone la aplicacion: `CURRENT_TIMESTAMP` es el
                // reloj del servidor, que en el VPS no es el de Bogota.
                'created_at' => now(),
                'updated_at' => now(),
            ],
            'user_id',
        );
    }

    private function telefono(): string
    {
        return '30' . mt_rand(0, 9) . ' ' . mt_rand(200, 899) . ' ' . mt_rand(1000, 9999);
    }

    private function direccion(int $i = 0): string
    {
        $vias = ['Calle', 'Carrera', 'Diagonal', 'Transversal'];

        return $vias[$i % 4] . ' ' . mt_rand(1, 98) . ' #' . mt_rand(1, 90) . '-' . mt_rand(10, 99);
    }

    private function sinTildes(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
    }

    private function resumen(
        array $areas,
        array $negocios,
        array $domiciliarios,
        array $compradores,
        array $pedidos,
    ): void {
        $this->command?->newLine();
        $this->command?->info('Listo. Todas las cuentas usan la contraseña: ' . self::CLAVE);
        $this->command?->newLine();

        $filas = [];

        foreach (Area::orderBy('id')->get() as $area) {
            $permisos = $area->permisos(Area::NIVEL_GESTOR);
            $gestiona = count(array_filter($permisos, fn ($p) => $p['manage']));

            $filas[] = [
                $area->name,
                "{$area->code}@" . self::DOMINIO,
                'Gestor',
                count($permisos) . ' ve / ' . $gestiona . ' gestiona',
            ];
            $filas[] = [
                '',
                "aux.{$area->code}@" . self::DOMINIO,
                'Solo consulta',
                count($permisos) . ' ve / 0 gestiona',
            ];
        }

        $this->command?->table(['Área', 'Correo', 'Nivel', 'Módulos'], $filas);

        $this->command?->line(sprintf(
            '  %d negocios · %d domiciliarios · %d compradores · %d pedidos',
            count($negocios),
            count($domiciliarios),
            count($compradores),
            count($pedidos),
        ));
    }
}
