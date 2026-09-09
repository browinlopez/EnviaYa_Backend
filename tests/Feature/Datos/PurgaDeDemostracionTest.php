<?php

use App\Models\User;
use Database\Seeders\DemoPurgeSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * LO SEMBRADO CON UN DOMINIO VIEJO TAMBIÉN SE PUEDE BORRAR.
 *
 * `DemoSeeder` marca a sus personas con el dominio del correo, y `DemoPurgeSeeder`
 * borra por esa marca. Cuando el dominio cambió de `demo.enviaya.test` a
 * `demo.example.com` —Bold rechaza `.test`— la purga se quedó mirando solo el
 * nuevo, y en toda base sembrada antes del cambio la tanda vieja se volvió
 * indeleble: la purga no la reconocía y el sembrador no la pisaba, porque
 * inserta por correo y esos correos ya no los usa nadie.
 *
 * El síntoma no parecía de datos. En el panel del tendero sus tres domiciliarios
 * salían seis veces, cada uno junto a su gemelo, y la tarjeta decía 6.
 */
it('borra a las personas de cualquier dominio de demostracion, no solo el de hoy', function () {
    $actual = User::factory()->create(['email' => 'yeimy.polo@' . DemoSeeder::DOMINIO]);
    $viejo  = User::factory()->create(['email' => 'yeimy.polo@demo.enviaya.test']);
    $real   = User::factory()->create(['email' => 'persona.de.verdad@gmail.com']);

    (new DemoPurgeSeeder)->run();

    $vivos = DB::table('user')->pluck('email');

    expect($vivos)->not->toContain($actual->email)
        ->and($vivos)->not->toContain($viejo->email)
        // Y lo que no lleva la marca no se toca, que es la mitad del trato.
        ->and($vivos)->toContain($real->email);
});

it('el dominio de hoy esta entre los que la purga reconoce', function () {
    /*
     * La lista se escribe a mano y crece hacia atrás. Si alguien cambia
     * `DOMINIO` y se olvida de dejar el anterior —o de poner el nuevo—, lo que
     * siembre a partir de ese día no habría forma de retirarlo.
     */
    expect(DemoSeeder::DOMINIOS_HISTORICOS)->toContain(DemoSeeder::DOMINIO)
        ->and(DemoSeeder::DOMINIOS_HISTORICOS)->toContain('demo.enviaya.test');
});
