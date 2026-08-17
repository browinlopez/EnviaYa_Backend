<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * RETIRA LOS DATOS DE DEMOSTRACIÓN
 *
 * La contrapartida de `DemoSeeder`. Existe porque unos datos de prueba sin
 * forma de quitarlos no son datos de prueba: son datos, y a la semana ya nadie
 * sabe cuáles eran de verdad.
 *
 * Borra únicamente lo que lleva la marca —correos @demo.enviaya.test y NIT
 * DEMO-…— y lo que cuelga de ello. Nada más: si un pedido apunta a un negocio
 * real, se queda.
 *
 *   php artisan db:seed --class=DemoPurgeSeeder
 */
class DemoPurgeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $usuarios = DB::table('user')
                ->where('email', 'like', '%@' . DemoSeeder::DOMINIO)
                ->pluck('user_id');

            $negocios = DB::table('business')
                ->where('NIT', 'like', DemoSeeder::NIT . '%')
                ->pluck('busines_id');

            $domiciliarios = DB::table('domiciliary')
                ->whereIn('user_id', $usuarios)
                ->pluck('domiciliary_id');

            $propietarios = DB::table('owner')->whereIn('user_id', $usuarios)->pluck('owner_id');
            $compradores  = DB::table('buyer')->whereIn('user_id', $usuarios)->pluck('buyer_id');

            /* --- Lo que cuelga de los pedidos, antes que los pedidos --- */
            $pedidos = DB::table('orderssales')
                ->whereIn('busines_id', $negocios)
                ->orWhereIn('buyer_id', $compradores)
                ->pluck('orderSales_id');

            DB::table('settlement_items')->whereIn('order_id', $pedidos)->delete();
            DB::table('settlements')
                ->whereIn('business_id', $negocios)
                ->orWhereIn('domiciliary_id', $domiciliarios)
                ->delete();

            DB::table('pqrs_notes')->whereIn(
                'pqrs_id',
                DB::table('pqrs')->where('code', 'like', 'DEMO-%')->pluck('id'),
            )->delete();
            DB::table('pqrs')->where('code', 'like', 'DEMO-%')->delete();

            DB::table('payments')->whereIn('orderSales_id', $pedidos)->delete();
            DB::table('orderssales_detail')->whereIn('orderSales_id', $pedidos)->delete();
            DB::table('orderssales')->whereIn('orderSales_id', $pedidos)->delete();

            /* --- SST --- */
            DB::table('domiciliary_documents')->whereIn('domiciliary_id', $domiciliarios)->delete();
            DB::table('safety_incidents')->whereIn('domiciliary_id', $domiciliarios)->delete();

            /* --- Marketing --- */
            $anunciantes = DB::table('advertisers')
                ->whereIn('business_id', $negocios)
                ->orWhere('contact_email', 'like', '%@' . DemoSeeder::DOMINIO)
                ->orWhere('contact_email', 'like', '%.demo')
                ->pluck('id');

            $campanas = DB::table('ad_campaigns')->whereIn('advertiser_id', $anunciantes)->pluck('id');

            DB::table('banner_events')->whereIn(
                'banner_id',
                DB::table('banners')->whereIn('campaign_id', $campanas)->pluck('id'),
            )->delete();
            DB::table('banners')->whereIn('campaign_id', $campanas)->delete();
            DB::table('ad_campaigns')->whereIn('id', $campanas)->delete();
            DB::table('advertisers')->whereIn('id', $anunciantes)->delete();

            DB::table('featured_businesses')->whereIn('business_id', $negocios)->delete();

            $cupones = ['BIENVENIDO10', 'DOMIGRATIS', 'MERCADO20', 'FLASH50', 'NAVIDAD25', 'PRUEBA00'];
            DB::table('coupon_redemptions')->whereIn(
                'coupon_id',
                DB::table('coupons')->whereIn('code', $cupones)->pluck('id'),
            )->delete();
            DB::table('coupons')->whereIn('code', $cupones)->delete();

            DB::table('push_campaigns')->whereIn('title', [
                'Tu mercado en 30 minutos',
                'Nuevos negocios cerca',
                'Fin de semana con 20%',
                'Borrador sin enviar',
            ])->delete();

            /* --- Comunidad --- */
            DB::table('business_reviews')->whereIn('busines_id', $negocios)->delete();
            DB::table('business_reviews')->whereIn('buyer_id', $compradores)->delete();
            DB::table('domiciliary_reviews')->whereIn('domiciliary_id', $domiciliarios)->delete();
            DB::table('domiciliary_reviews')->whereIn('buyer_id', $compradores)->delete();

            $chats = DB::table('chat_participants')->whereIn('user_id', $usuarios)->pluck('chat_id');
            DB::table('messages')->whereIn('chat_id', $chats)->delete();
            DB::table('chat_participants')->whereIn('chat_id', $chats)->delete();
            DB::table('chats')->whereIn('chat_id', $chats)->delete();

            /* --- Catálogo y fichas --- */
            DB::table('products_business')->whereIn('busines_id', $negocios)->delete();
            DB::table('business_domiciliary')->whereIn('busines_id', $negocios)->delete();
            DB::table('business_domiciliary')->whereIn('domiciliary_id', $domiciliarios)->delete();
            DB::table('owner_busines')->whereIn('busines_id', $negocios)->delete();
            DB::table('owner_busines')->whereIn('owner_id', $propietarios)->delete();
            DB::table('business')->whereIn('busines_id', $negocios)->delete();

            DB::table('residential_complexes')->whereIn('name', [
                'Conjunto Villa Carolina',
                'Portal de Alameda',
                'Urbanización La Arboleda',
                'Conjunto Miramar',
                'Altos de Malambo',
            ])->delete();

            /* --- Y por último las personas --- */
            DB::table('buyer_complex')->whereIn('buyer_id', $compradores)->delete();
            DB::table('buyer')->whereIn('user_id', $usuarios)->delete();
            DB::table('domiciliary')->whereIn('user_id', $usuarios)->delete();
            DB::table('owner')->whereIn('user_id', $usuarios)->delete();
            DB::table('user_address')->whereIn('user_id', $usuarios)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $usuarios)
                ->delete();
            DB::table('user')->whereIn('user_id', $usuarios)->delete();

            $this->command?->info(sprintf(
                'Retirados: %d personas, %d negocios, %d pedidos.',
                $usuarios->count(),
                $negocios->count(),
                $pedidos->count(),
            ));
        });
    }
}
