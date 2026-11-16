<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * QUE ALGUIEN SE ENTERE DE QUE NADIE SACA LOS TRABAJOS DE LA COLA
 *
 * Salió al recorrer el sistema entero: **noventa y cuatro trabajos llevaban en
 * la cola desde el 8 de septiembre**, con cero intentos. Ochenta avisos push,
 * siete trazados de ruta, siete cargas de catálogo. Se drenaron a mano y
 * pasaron los noventa y cuatro sin un solo fallo, así que el código estaba
 * bien: lo que no existía era manera de enterarse.
 *
 * Es una avería invisible desde fuera. `QUEUE_CONNECTION` es `database`, así
 * que encolar siempre funciona y la petición del comprador acaba en 201 igual.
 * Lo que deja de pasar es lo de después: el tendero no recibe el aviso del
 * pedido nuevo, el mapa no traza la ruta, el catálogo se queda quieto. Tres
 * síntomas en tres pantallas y ninguno apuntando a un trabajador muerto.
 *
 * Lo que se fija aquí es que el comando distinga los tres casos y que avise
 * FUERA de la terminal, porque un comando que solo imprime en el cron no
 * vigila nada.
 */
function encolar(int $hace_minutos): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DePrueba', 'job' => 'x']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        // Entero de Unix, no fecha: es como guarda Laravel esta columna, y
        // compararla con `now()` de Eloquent daria cualquier cosa.
        'created_at' => time() - ($hace_minutos * 60),
    ]);
}

function vaciarColas(): void
{
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
}

/*
 * LOS CORREOS DE VERDAD, no el doble de Laravel.
 *
 * El comando usa `Mail::raw`, que no construye un Mailable, y `Mail::fake()`
 * cuenta Mailables: `assertSentCount(1)` daba cero sobre un correo que SI se
 * mandaba. Y `Mail::fake()` sustituye el gestor entero, asi que tampoco se
 * puede deshacer a mitad de la prueba.
 *
 * `MAIL_MAILER=array` —que `phpunit.xml` ya fija— guarda los mensajes tal como
 * salieron. Es una prueba mas fuerte: ve el mensaje, no la intencion.
 */
function correosEnviados(): array
{
    // `messages()` devuelve una Collection, no un array: declararlo `array` a
    // secas daba un TypeError en tres pruebas de golpe.
    return Mail::mailer()->getSymfonyTransport()->messages()->all();
}

function olvidarLosCorreos(): void
{
    Mail::mailer()->getSymfonyTransport()->flush();
}

beforeEach(function () {
    vaciarColas();
    olvidarLosCorreos();
    config(['rendimiento.colas.minutos_de_espera' => 15,
        'rendimiento.colas.maximo_esperando' => 500]);
});

it('con la cola vacia o al dia, sale bien y no avisa', function () {
    $this->artisan('colas:comprobar --avisar')
        ->assertSuccessful();

    encolar(hace_minutos: 2);

    $this->artisan('colas:comprobar --avisar')
        ->expectsOutputToContain('Las colas van al dia')
        ->assertSuccessful();

    expect(correosEnviados())->toBeEmpty();
});

it('un trabajo viejo esperando es el aviso que importa', function () {
    /*
     * ES ESTA la comprobación con valor, y no la del número de trabajos. Una
     * cola con mil elementos puede ser un rato de tráfico; el PRIMERO de la
     * fila esperando media hora no tiene explicación inocente. Un solo trabajo
     * basta para disparar el aviso, y eso es a propósito.
     */
    encolar(hace_minutos: 40);

    $this->artisan('colas:comprobar')
        ->expectsOutputToContain('40 minutos esperando')
        ->assertFailed();
});

it('un trabajo fallido tambien avisa, aunque la cola este vacia', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\DePrueba']),
        'exception' => 'se agoto',
        'failed_at' => now(),
    ]);

    $this->artisan('colas:comprobar')
        ->expectsOutputToContain('failed_jobs')
        ->assertFailed();
});

it('avisa por correo, y a una direccion de verdad', function () {
    encolar(hace_minutos: 60);

    $this->artisan('colas:comprobar --avisar')->assertFailed();

    $mensajes = correosEnviados();

    expect($mensajes)->toHaveCount(1);

    $correo = $mensajes[0]->getOriginalMessage();
    $destino = $correo->getTo()[0]->getAddress();

    /*
     * La misma exigencia que los avisos de la copia de seguridad, y por el
     * mismo motivo: ahi el destinatario era `your@example.com` y el aviso de
     * que no habia copia se mandaba a ninguna parte. Un aviso a una direccion
     * que no existe es peor que no tener aviso, porque parece que lo tienes.
     */
    expect($destino)
        ->not->toContain('example.com')
        ->not->toStartWith('your@')
        ->toMatch('/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i');

    // Y que el cuerpo diga lo que deja de pasar, que es lo que hace que
    // alguien actue en vez de archivarlo.
    // `getTextBody()` y no `getBody()->bodyToString()`: lo segundo viene
    // codificado en quoted-printable, que corta las lineas a los 76
    // caracteres CON un `=` en medio de la palabra. Afirmar sobre eso es
    // afirmar sobre la codificacion.
    expect($correo->getTextBody())
        ->toContain('60')
        ->toContain('pedido nuevo');
});

it('sin --avisar no manda nada, para poder mirarlo a mano', function () {
    encolar(hace_minutos: 60);

    $this->artisan('colas:comprobar')->assertFailed();

    expect(correosEnviados())->toBeEmpty();
});

it('el planificador lo corre a diario', function () {
    /*
     * Un comando que nadie lanza no vigila nada. Esto comprueba que esté en el
     * cron Y que lleve `--avisar`: sin la bandera, la salida se va a
     * /dev/null y volvemos al punto de partida.
     */
    $this->artisan('schedule:list')
        ->expectsOutputToContain('colas:comprobar --avisar')
        ->assertSuccessful();
});
