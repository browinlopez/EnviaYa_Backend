<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    /*
     * EL POR DEFECTO NO ES DECORACION.
     *
     * Venia `hello@example.com`, que es el valor del esqueleto de Laravel. El
     * `.env` de produccion si tiene `MAIL_FROM_ADDRESS`, asi que hoy no se
     * nota; el dia que falte, TODO el correo de la plataforma —la confirmacion
     * del pedido, el restablecimiento de contrasena— saldria con un remitente
     * de un dominio que no es nuestro, y un servidor SMTP serio rechaza o
     * marca como basura un remitente que no coincide con el dominio
     * autenticado. El fallo no seria «no llega un correo»: seria todos.
     *
     * El dominio es .com, no .com.co: el .com.co no existe y rebota.
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'gerencia@vecipaya.com'),
        'name' => env('MAIL_FROM_NAME', "VeciPa'Ya"),
    ],

    /*
    |--------------------------------------------------------------------------
    | A DÓNDE CONTESTA QUIEN RECIBE
    |--------------------------------------------------------------------------
    |
    | ESTO EXISTE PARA PODER ALINEAR EL REMITENTE SIN PERDER LA DIRECCIÓN DE
    | LA MARCA, y eso hace falta porque la plataforma envía por el SMTP de
    | Gmail con una cuenta `@gmail.com`, mientras el remitente dice
    | `@vecipaya.com`.
    |
    | Quien recibe el correo comprueba el remitente contra quien lo entregó:
    | el SPF que valida es el de Gmail, y la firma DKIM la pone `gmail.com`.
    | Un `From:` de otro dominio no queda amparado por ninguna de las dos, y
    | ese desajuste es la causa corriente de que los correos de verificación
    | de cuenta caigan en la carpeta de basura —justo la pieza de la que
    | depende que alguien termine de registrarse—.
    |
    | Con esto hay dos salidas, y las dos funcionan sin pagar nada:
    |
    |   a) Alinear: `MAIL_FROM_ADDRESS` = la cuenta que envía, y
    |      `MAIL_REPLY_TO_ADDRESS=gerencia@vecipaya.com`. El correo entrega
    |      bien, y quien contesta le escribe a la dirección de la marca. Lo
    |      que se cede es cosmético: en la bandeja se lee el nombre
    |      —«VeciPa'Ya»— y debajo la dirección que envía.
    |
    |   b) Mantener el remitente del dominio propio, y entonces hay que
    |      verificar el alias en la cuenta de Gmail Y añadir
    |      `include:_spf.google.com` al SPF de `vecipaya.com`. Es más
    |      presentable y depende del DNS, no de este fichero.
    |
    | SIN LA VARIABLE NO SE AÑADE LA CABECERA, así que quien no configure nada
    | queda exactamente como estaba. No hace falta filtrar el arreglo para
    | conseguirlo: Laravel pregunta `! empty($this->replyTo['address'])` antes
    | de ponerla —`Illuminate\Mail\Mailer::createMessage`, línea 564—, y eso
    | descarta igual el `null` de la variable ausente y la cadena vacía de la
    | variable que alguien creó y no rellenó.
    |
    | Acá había un `array_filter` para eso. Se quitó después de MEDIR que no
    | cambiaba nada: la mutación que lo borraba no hacía fallar ninguna prueba,
    | porque no había nada que romper. Las dos pruebas de
    | `LoQueNoAvisaCuandoEstaMalTest` fijan las dos ausencias.
    |
    | `php artisan configuracion:comprobar` dice si el remitente y la cuenta
    | que envía son del mismo dominio.
    */
    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS'),
        'name' => env('MAIL_REPLY_TO_NAME', env('MAIL_FROM_NAME', "VeciPa'Ya")),
    ],

];
