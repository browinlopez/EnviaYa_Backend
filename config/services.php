<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bold' => [
        'base_url' => env('BOLD_BASE_URL', 'https://integrations.api.bold.co'),
        'api_key'  => env('BOLD_API_KEY'),
        'webhook_secret' => env('BOLD_WEBHOOK_SECRET', ''),
    ],

    // Tarifa de domicilio en COP. El total de la orden se calcula en el
    // servidor: subtotal de productos (precios reales) + esta tarifa.
    'delivery_fee' => env('DELIVERY_FEE', 2000),

    // Cuántos pedidos puede llevar un domiciliario al mismo tiempo (estado 3).
    // Al llegar al tope no puede aceptar ni le pueden despachar más hasta
    // que entregue alguno.
    'max_active_deliveries' => env('MAX_ACTIVE_DELIVERIES', 3),

    // Porción de la tarifa de domicilio que le corresponde al domiciliario.
    // El resto queda para la plataforma. Se calcula al crear la orden y se
    // guarda ahí, así que cambiar este valor no reescribe lo ya entregado.
    /*
     * Comisión de la plataforma sobre la venta del negocio.
     *
     * Es el valor de fábrica: el vigente lo publica el panel y lo lee
     * `Ajustes::valor('operacion.comision_plataforma')`. Hasta ahora la
     * plataforma no le retenía NADA al negocio —`LiquidacionService` daba
     * `platform_fee = 0` siempre— y su único ingreso era su porción del
     * domicilio.
     */
    'platform_commission' => (float) env('PLATFORM_COMMISSION', 0.03),

    'domiciliary_share' => env('DOMICILIARY_SHARE', 0.25),

    /*
     * Datos de la empresa que firma el acuerdo de vinculación con los
     * domiciliarios. Van en configuración y no en el código del documento
     * porque son los que cambian: NIT, representante legal y ciudad.
     *
     * Si el NIT o el representante están vacíos, el contrato sale con la
     * línea en blanco, tal cual la plantilla en papel.
     */
    /*
     * NOTIFICACIONES A LOS TELÉFONOS (Firebase Cloud Messaging).
     *
     * Sin `FCM_CREDENTIALS` el módulo de Notificaciones sigue funcionando —se
     * arman las campañas, se resuelve el segmento, se cuentan los dispositivos—
     * pero no entrega nada, y la pantalla lo DICE. Nunca finge haber enviado.
     *
     * La cuenta de servicio la da Firebase en Configuración del proyecto →
     * Cuentas de servicio → Generar nueva clave. NUNCA va al repositorio: su
     * clave privada da acceso de administrador al proyecto —mandarle una
     * notificación a cualquier usuario de la plataforma, entre otras cosas— y
     * este repositorio es público. Subida una vez, queda en el historial
     * aunque después se borre el archivo.
     *
     * DOS FORMAS DE DÁRSELA, y con despliegue desde GitHub sólo sirve la
     * primera:
     *
     *  · `FCM_CREDENTIALS_JSON` — el contenido del archivo, en la variable de
     *    entorno del servidor. En base64 preferiblemente: es una sola línea, y
     *    así el panel no estropea los saltos de línea de la clave privada.
     *
     *  · `FCM_CREDENTIALS` — la ruta a un archivo puesto a mano en el
     *    servidor, fuera de `public/`.
     */
    'fcm' => [
        'credentials'      => env('FCM_CREDENTIALS', ''),
        'credentials_json' => env('FCM_CREDENTIALS_JSON', ''),
        'project_id'       => env('FCM_PROJECT_ID', ''),
    ],

    /*
     * Apple, para las notificaciones a iPhone.
     *
     * Van directo a APNs y no por Firebase porque el token que registra la app
     * en iOS es el NATIVO de Apple, y Firebase solo acepta los suyos. Ver
     * `PushApns`.
     *
     * `APNS_PRODUCTION` tiene que coincidir con el `aps-environment` con el que
     * se compilo la app: los tokens de sandbox no valen en produccion ni al
     * reves, y Apple responde `BadDeviceToken` sin decir cual es el problema.
     */
    'apns' => [
        // La clave .p8, en base64 y en una linea (o su ruta en el servidor).
        'key'        => env('APNS_KEY', ''),
        'key_path'   => env('APNS_KEY_PATH', ''),
        'key_id'     => env('APNS_KEY_ID', ''),
        'team_id'    => env('APNS_TEAM_ID', ''),
        // El bundle de la app: es el `apns-topic` de cada envio.
        'bundle_id'  => env('APNS_BUNDLE_ID', ''),
        'production' => env('APNS_PRODUCTION', false),
    ],

    'contrato' => [
        'empresa'        => env('CONTRATO_EMPRESA', 'MARCAVA GROUP S.A.S.'),
        'nit'            => env('CONTRATO_NIT', ''),
        'plataforma'     => env('CONTRATO_PLATAFORMA', "VECIPA'YA"),
        'representante'  => env('CONTRATO_REPRESENTANTE', ''),
        'representante_cc' => env('CONTRATO_REPRESENTANTE_CC', ''),
        'ciudad'         => env('CONTRATO_CIUDAD', 'Barranquilla'),
    ],

    /*
     * LA WEB PÚBLICA
     *
     * De acá salen los enlaces que el backend manda hacia fuera: a dónde va
     * el usuario después de confirmar su correo y qué logotipo se pinta en los
     * correos de aviso. Estaba escrito a mano en tres archivos apuntando a
     * vecipaya.com, que dejó de responder; el enlace de confirmación llevaba
     * a un dominio caído y a una ruta que la landing ni siquiera tenía.
     */
    'sitio' => [
        'url' => rtrim(env('SITIO_URL', 'https://enviaya.com.co'), '/'),

        /*
         * EL BUZÓN SIGUE EN vecipaya.com A PROPÓSITO. No es un descuido del
         * barrido de dominios.
         *
         * El sitio web de vecipaya.com está caído y por eso se retiró de todo
         * lo demás, pero el correo es independiente: ese dominio tiene MX
         * activos (mx1 y mx2.hostinger.com) y enviaya.com.co NO tiene ninguno
         * —comprobado el 2026-08-22 contra 8.8.8.8—. Publicar una dirección
         * en el dominio nuevo la deja rebotando, y un correo que rebota es
         * peor que uno viejo: quien escribe cree que llegó.
         *
         * Se cambia el día que enviaya.com.co tenga correo configurado.
         */
        'correo'         => env('SITIO_CORREO', 'gerencia@vecipaya.com'),
        'correo_soporte' => env('SITIO_CORREO_SOPORTE', 'gerencia@vecipaya.com'),
    ],

    /*
     * MAPA DE COBERTURA DE LA WEB PÚBLICA
     *
     * Los conjuntos residenciales se publican con su coordenada exacta, igual
     * que los comercios. Es lo que hace útil al mapa, pero es una decisión de
     * producto: si algún día se prefiere no señalar dónde vive la gente, se
     * apaga acá y el mapa sigue funcionando solo con los comercios.
     */
    'cobertura' => [
        'incluir_conjuntos' => filter_var(
            env('COBERTURA_INCLUIR_CONJUNTOS', true),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],
];
