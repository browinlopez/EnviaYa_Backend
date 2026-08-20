<?php

use App\Services\Totp;

/**
 * TOTP CONTRA LOS VECTORES DEL RFC 6238
 *
 * El algoritmo está escrito a mano para no traer un paquete por sesenta líneas.
 * Lo que compensa esa decisión es esto: los mismos vectores de prueba que
 * publica el RFC y que pasa cualquier implementación seria. Si el código de acá
 * coincide con ellos, coincide con Google Authenticator, con Authy y con
 * 1Password; si no coincidiera, nadie podría entrar y no habría forma de saber
 * por qué.
 *
 * El secreto de los vectores es el ASCII "12345678901234567890", que en base32
 * es GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ.
 */

const SECRETO_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('reproduce los códigos del RFC 6238', function (int $instante, string $esperado) {
    /*
     * El RFC publica los vectores en OCHO dígitos; las apps de autenticación
     * usan seis, que son los seis últimos del mismo número. Se comprueban los
     * ocho para que la coincidencia sea con el vector completo y no con una
     * coincidencia parcial afortunada.
     */
    expect(Totp::codigo(SECRETO_RFC, $instante, 8))->toBe($esperado);
})->with([
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
]);

it('acepta el código del momento', function () {
    $ahora = time();

    expect(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $ahora), $ahora))
        ->toBeTrue();
});

it('tolera hasta medio minuto de desfase entre relojes', function () {
    $ahora = time();

    /*
     * El reloj del teléfono y el del servidor no coinciden al segundo, y teclear
     * seis dígitos lleva unos cuantos. Sin tolerancia, uno de cada varios
     * códigos correctos se rechaza y la gente concluye que "esto no funciona".
     */
    expect(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $ahora - 30), $ahora))->toBeTrue();
    expect(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $ahora + 30), $ahora))->toBeTrue();
});

it('rechaza un código de hace dos minutos', function () {
    // Con demasiada tolerancia el código vale minutos y deja de ser de un solo
    // uso: quien mire por encima del hombro tendría tiempo de sobra.
    $ahora = time();

    expect(Totp::verificar(SECRETO_RFC, Totp::codigo(SECRETO_RFC, $ahora - 120), $ahora))
        ->toBeFalse();
});

it('rechaza basura sin reventar', function () {
    foreach (['', 'abcdef', '12345', '1234567', 'null'] as $intento) {
        expect(Totp::verificar(SECRETO_RFC, $intento))->toBeFalse();
    }
});

it('un secreto nuevo no vale para el código de otro', function () {
    $uno = Totp::secreto();
    $dos = Totp::secreto();
    $ahora = time();

    expect($uno)->not->toBe($dos);
    expect(Totp::verificar($dos, Totp::codigo($uno, $ahora), $ahora))->toBeFalse();
});

it('el secreto es base32 utilizable por las apps', function () {
    $s = Totp::secreto();

    // Las apps solo aceptan el alfabeto base32: una minúscula o un 1 hacen que
    // el escaneo falle sin decir por qué.
    expect($s)->toMatch('/^[A-Z2-7]{32}$/');
});

it('la dirección del QR lleva lo que la app necesita', function () {
    $uri = Totp::uri('ABC234', 'liliana@enviaya.test', "VeciPa'Ya");

    expect($uri)->toStartWith('otpauth://totp/');
    expect($uri)->toContain('secret=ABC234');
    expect($uri)->toContain('digits=6');
    expect($uri)->toContain('period=30');
    // El correo, para distinguirla entre varias cuentas en la misma app.
    expect($uri)->toContain(rawurlencode('liliana@enviaya.test'));
});

it('ignora los espacios con que la gente copia el secreto', function () {
    $ahora = time();
    $codigo = Totp::codigo(SECRETO_RFC, $ahora);

    // Al pegarlo a mano casi siempre viaja en grupos de cuatro.
    $conEspacios = trim(chunk_split(SECRETO_RFC, 4, ' '));

    expect(Totp::verificar($conEspacios, $codigo, $ahora))->toBeTrue();
});
