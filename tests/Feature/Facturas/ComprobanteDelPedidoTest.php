<?php

use App\Models\Invoice\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * EL COMPROBANTE, PARA EL COMPRADOR Y PARA SU TIENDA
 *
 * Lo que se vigila acá es la puerta, no el documento —de eso ya se encarga
 * `ComprobantesTest`—. Un comprobante lleva dentro el nombre, el teléfono y la
 * dirección de quien compró: que solo lo alcance quien hizo el pedido y la
 * tienda que lo despachó es la mitad de la función.
 */

/*
 * UN PEDIDO ENTREGADO, CON SU TIENDA Y SU DUEÑO, listo para comprobante.
 *
 * El nombre es largo a propósito. Una ayuda declarada dentro de un archivo de
 * pruebas es una función GLOBAL en cuanto Pest carga el archivo, así que dos
 * archivos con una `pedidoEntregado()` cada uno tumban la suite entera con un
 * «cannot redeclare» —ya pasó acá, y `OperacionModulosTest` tiene la suya—.
 * Esta solo la usa este archivo; si algún día la necesita otro, se muda a
 * `tests/Pest.php` como se hizo con las del conjunto.
 */
function compraEntregadaConTienda(): array
{
    $e = pedidoCancelable();

    $producto = DB::table('products')->insertGetId([
        'name' => 'Arroz Diana 500g', 'state' => 1,
    ], 'products_id');

    DB::table('orderssales_detail')->insert([
        'orderSales_id' => $e['id'],
        'product_id'    => $producto,
        'amount'        => 2,
        'unit_price'    => 6250,
    ]);

    DB::table('orderssales')->where('orderSales_id', $e['id'])->update([
        'state'         => 4,
        'delivery_date' => now(),
    ]);

    return $e;
}

it('el comprador ve el comprobante de su pedido', function () {
    $e = compraEntregadaConTienda();

    $r = $this->getJson("/v1/orders/{$e['id']}/comprobante")->assertOk();

    expect($r->json('order_id'))->toBe($e['id'])
        ->and($r->json('invoice_number'))->not->toBeEmpty()
        ->and($r->json('total'))->toEqual(18500)
        // La foto guardada viaja entera: sin ella la pantalla no tiene qué pintar.
        ->and($r->json('snapshot.negocio.nombre'))->toBe('Tienda de la prueba')
        ->and($r->json('snapshot.renglones.0.producto'))->toBe('Arroz Diana 500g')
        ->and($r->json('pdf_url'))->toContain('/v1/comprobantes/');
});

/*
 * SE EMITE AL PEDIRLO SI FALTABA. Un pedido entregado antes de que esto
 * existiera, o uno cuya emisión se cayó por un corte, se quedaría sin
 * comprobante para siempre si solo se mirara lo ya emitido.
 */
it('emite el comprobante al vuelo cuando el pedido lo entregaron sin el', function () {
    $e = compraEntregadaConTienda();

    expect(Invoice::count())->toBe(0);

    $this->getJson("/v1/orders/{$e['id']}/comprobante")->assertOk();

    expect(Invoice::count())->toBe(1);

    // Y pedirlo dos veces no emite dos: el servicio es idempotente.
    $this->getJson("/v1/orders/{$e['id']}/comprobante")->assertOk();

    expect(Invoice::count())->toBe(1);
});

it('un pedido que no se ha entregado lo dice con esas palabras', function () {
    $e = pedidoCancelable(); // nace en estado 1

    $this->getJson("/v1/orders/{$e['id']}/comprobante")
        ->assertNotFound()
        ->assertJsonFragment(['message' => 'El comprobante se emite cuando el pedido se entrega.']);

    expect(Invoice::count())->toBe(0);
});

/*
 * EL COMPROBANTE DE OTRO NO SE VE, Y SE RESPONDE 404 Y NO 403. Un 403
 * confirmaría que ese número de pedido existe, y recorriendo la numeración se
 * sabría cuánto vende la plataforma.
 */
it('el comprobante de otro comprador no se alcanza', function () {
    $e = compraEntregadaConTienda();

    $intruso = User::factory()->create(['rol' => 1]);
    DB::table('buyer')->insert(['user_id' => $intruso->user_id, 'state' => 1]);
    Sanctum::actingAs($intruso);

    $this->getJson("/v1/orders/{$e['id']}/comprobante")->assertNotFound();
});

it('la tienda ve el comprobante de lo que despachó, y solo eso', function () {
    $e = compraEntregadaConTienda();

    Sanctum::actingAs($e['tendero']);
    $this->getJson("/v1/negocio/pedidos/{$e['id']}/comprobante")->assertOk();

    // Otro tendero, con su propia tienda, no alcanza este pedido.
    $otro = compraEntregadaConTienda();
    Sanctum::actingAs($otro['tendero']);
    $this->getJson("/v1/negocio/pedidos/{$e['id']}/comprobante")->assertNotFound();
});

describe('el enlace del PDF', function () {
    it('abre sin sesión, porque lo abre un navegador', function () {
        $e = compraEntregadaConTienda();
        $url = $this->getJson("/v1/orders/{$e['id']}/comprobante")->json('pdf_url');

        // Sin token: es exactamente lo que hace el navegador del teléfono.
        $r = $this->withoutToken()->get($url);

        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($r->getContent(), 0, 4))->toBe('%PDF');
    });

    it('sin firma válida no abre', function () {
        $e = compraEntregadaConTienda();
        $url = $this->getJson("/v1/orders/{$e['id']}/comprobante")->json('pdf_url');

        // Le cambiamos un carácter a la firma: es el ataque entero.
        $this->get(substr($url, 0, -1) . 'x')->assertForbidden();

        // Y sin firma ninguna tampoco.
        $this->get(strtok($url, '?'))->assertForbidden();
    });

    it('caduca', function () {
        $e = compraEntregadaConTienda();
        $url = $this->getJson("/v1/orders/{$e['id']}/comprobante")->json('pdf_url');

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
    });
});
