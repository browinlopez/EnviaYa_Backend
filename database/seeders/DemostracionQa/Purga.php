<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Retira solo lo marcado (correo @demo.vecipaya.com, NIT DEMOQA-) y lo que cuelga, hijos primero.
final class Purga
{
    /** @var array<string, int> tabla => filas borradas */
    private array $borradas = [];

    /** @var array<string, true> */
    private array $tablas = [];

    /** @return array<string, int> */
    public function correr(): array
    {
        $u = $this->ids('user', 'user_id', fn ($q) => $q->where('email', 'like', '%@'.Escenario::DOMINIO));
        $n = $this->ids('business', 'busines_id', fn ($q) => $q->where('NIT', 'like', Escenario::MARCA.'%'));
        $x = $this->ids('residential_complexes', 'complex_id', fn ($q) => $q->where('nit', 'like', Escenario::MARCA.'%'));

        if ($u === [] && $n === [] && $x === []) {
            return [];
        }

        foreach (Schema::getTables() as $t) {
            $this->tablas[$t['name']] = true;
        }

        $c = $this->ids('buyer', 'buyer_id', fn ($q) => $q->whereIn('user_id', $u ?: [0]));
        $o = $this->ids('owner', 'owner_id', fn ($q) => $q->whereIn('user_id', $u ?: [0]));
        $d = $this->ids('domiciliary', 'domiciliary_id', fn ($q) => $q->whereIn('user_id', $u ?: [0]));
        $p = $this->ids('orderssales', 'orderSales_id', fn ($q) => $q->whereIn('busines_id', $n ?: [0])->orWhereIn('buyer_id', $c ?: [0]));

        $this->pedidos($p, $n, $d);
        $this->negocios($n, $u, $c, $d, $o);
        $this->conjuntos($x, $u, $c, $d);
        $this->personas($u, $c, $o, $d);

        return array_filter($this->borradas);
    }

    private function pedidos(array $p, array $n, array $d): void
    {
        $liquidaciones = $this->ids('settlements', 'id', fn ($q) => $q->whereIn('business_id', $n ?: [0])->orWhereIn('domiciliary_id', $d ?: [0]));
        $intentos = $this->ids('payment_intents', 'id', fn ($q) => $q->whereIn('orderSales_id', $p ?: [0]));
        $creditos = $this->ids('store_credits', 'id', fn ($q) => $q->whereIn('busines_id', $n ?: [0]));
        $depositos = $this->ids('cash_deposits', 'id', fn ($q) => $q->whereIn('busines_id', $n ?: [0])->orWhereIn('domiciliary_id', $d ?: [0]));

        $this->borrar('invoices', 'orderSales_id', $p);
        $this->borrar('invoices', 'busines_id', $n);
        $this->borrar('settlement_items', 'order_id', $p);
        $this->borrar('settlement_items', 'settlement_id', $liquidaciones);
        $this->borrar('settlements', 'id', $liquidaciones);
        $this->borrar('payment_transactions', 'payment_intent_id', $intentos);
        $this->borrar('payment_intents', 'id', $intentos);
        $this->borrar('payments', 'orderSales_id', $p);
        $this->borrar('coupon_redemptions', 'order_id', $p);
        $this->borrar('status_history', 'order_id', $p);
        $this->borrar('novedades_de_entrega', 'order_id', $p);
        $this->borrar('novedades_de_entrega', 'domiciliary_id', $d);
        $this->borrar('returns', 'orderSales_id', $p);
        $this->borrar('store_credit_movements', 'order_id', $p);
        $this->borrar('store_credit_movements', 'store_credit_id', $creditos);
        $this->borrar('store_credits', 'id', $creditos);
        $this->borrar('cash_movements', 'order_id', $p);
        $this->borrar('cash_movements', 'deposit_id', $depositos);
        $this->borrar('cash_movements', 'domiciliary_id', $d);
        $this->borrar('cash_deposits', 'id', $depositos);
        $this->borrar('order_geolocation', 'orderSales_id', $p);
        $this->borrar('order_geolocation', 'domiciliary_id', $d);
        $this->borrar('orders_promotions', 'orderSales_id', $p);
        $this->borrar('orderssales_detail', 'orderSales_id', $p);
        $this->borrar('orderssales', 'orderSales_id', $p);
    }

    private function negocios(array $n, array $u, array $c, array $d, array $o): void
    {
        $ventas = $this->ids('ventas_mostrador', 'venta_id', fn ($q) => $q->whereIn('busines_id', $n ?: [0]));
        $gastos = $this->ids('gastos_del_negocio', 'gasto_id', fn ($q) => $q->whereIn('busines_id', $n ?: [0]));
        $promos = $this->ids('promotions', 'promotion_id', fn ($q) => $q->whereIn('busines_id', $n ?: [0]));

        // Un producto propio que otra tienda se copió no se borra: la foránea lo impide.
        $productos = $this->ids('products', 'products_id', fn ($q) => $q->whereIn('busines_id_propietario', $n ?: [0])
            ->whereNotExists(fn ($s) => $s->from('products_business as pb')->whereColumn('pb.products_id', 'products.products_id')
                ->whereNotIn('pb.busines_id', $n ?: [0])->selectRaw('1')));

        $this->borrar('ventas_mostrador_lineas', 'venta_id', $ventas);
        $this->borrar('ventas_mostrador', 'venta_id', $ventas);
        $this->borrar('gastos_del_mes', 'gasto_id', $gastos);
        $this->borrar('gastos_del_negocio', 'gasto_id', $gastos);
        $this->borrar('orders_promotions', 'promotion_id', $promos);
        $this->borrar('promotion_products', 'promotion_id', $promos);
        $this->borrar('promotions', 'promotion_id', $promos);
        $this->borrar('business_reviews', 'busines_id', $n);
        $this->borrar('business_reviews', 'buyer_id', $c);
        $this->borrar('domiciliary_reviews', 'domiciliary_id', $d);
        $this->borrar('domiciliary_reviews', 'buyer_id', $c);
        $this->borrar('business_user_affiliations', 'busines_id', $n);
        $this->borrar('business_user_affiliations', 'user_id', $u);
        $this->borrar('business_user_favorites', 'busines_id', $n);
        $this->borrar('business_user_favorites', 'user_id', $u);
        $this->borrar('products_business', 'busines_id', $n);
        $this->borrar('product_aliases', 'products_id', $productos);
        $this->borrar('products', 'products_id', $productos);
        $this->borrar('business_domiciliary', 'busines_id', $n);
        $this->borrar('business_domiciliary', 'domiciliary_id', $d);
        $this->borrar('owner_busines', 'busines_id', $n);
        $this->borrar('owner_busines', 'owner_id', $o);
        $this->borrar('business_hours', 'busines_id', $n);
        $this->borrar('featured_businesses', 'business_id', $n);
        $this->borrar('catalog_uploads', 'busines_id', $n);
        $this->borrar('business', 'busines_id', $n);
    }

    private function conjuntos(array $x, array $u, array $c, array $d): void
    {
        $this->borrar('complex_entries', 'complex_id', $x);
        $this->borrar('complex_entries', 'domiciliary_id', $d);
        $this->borrar('complex_shifts', 'complex_id', $x);
        $this->borrar('complex_shifts', 'user_id', $u);
        $this->borrar('complex_staff', 'complex_id', $x);
        $this->borrar('complex_staff', 'user_id', $u);
        $this->borrar('complex_change_requests', 'complex_id', $x);
        $this->borrar('buyer_complex', 'complex_id', $x);
        $this->borrar('buyer_complex', 'buyer_id', $c);
        $this->borrar('residential_complexes', 'complex_id', $x);
    }

    private function personas(array $u, array $c, array $o, array $d): void
    {
        $this->borrar('domiciliary_documents', 'domiciliary_id', $d);
        $this->borrar('domiciliary', 'domiciliary_id', $d);
        $this->borrar('owner', 'owner_id', $o);
        $this->borrar('buyer', 'buyer_id', $c);
        $this->borrar('user_address', 'user_id', $u);
        $this->borrar('device_tokens', 'user_id', $u);
        $this->borrar('notifications', 'user_id', $u);
        $this->borrar('sessions', 'user_id', $u);
        $this->borrar('personal_access_tokens', 'tokenable_id', $u, fn ($q) => $q->where('tokenable_type', User::class));
        $this->borrar('model_has_roles', 'model_id', $u, fn ($q) => $q->where('model_type', User::class));
        $this->borrar('model_has_permissions', 'model_id', $u, fn ($q) => $q->where('model_type', User::class));
        $this->borrar('user', 'user_id', $u);
    }

    /** @return list<int> */
    private function ids(string $tabla, string $llave, callable $filtro): array
    {
        return DB::table($tabla)->tap($filtro)->pluck($llave)->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int> $ids */
    private function borrar(string $tabla, string $columna, array $ids, ?callable $ademas = null): void
    {
        if ($ids === [] || ($this->tablas !== [] && ! isset($this->tablas[$tabla]))) {
            return;
        }

        foreach (array_chunk($ids, 500) as $parte) {
            $cuantas = DB::table($tabla)->whereIn($columna, $parte)->when($ademas, fn ($q) => $q->tap($ademas))->delete();
            $this->borradas[$tabla] = ($this->borradas[$tabla] ?? 0) + $cuantas;
        }
    }
}
