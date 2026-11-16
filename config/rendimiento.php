<?php

return [

    /*
    |--------------------------------------------------------------------------
    | La medida de cada peticion
    |--------------------------------------------------------------------------
    |
    | Una linea por peticion en `storage/logs/latencia-AAAA-MM-DD.log`, que es
    | de donde salen los percentiles de `scripts/percentiles_de_latencia.php`.
    |
    | Se apaga desde el entorno y no tocando codigo, porque el dia que el
    | fichero moleste hace falta pararlo sin desplegar. En las pruebas va
    | apagada por `phpunit.xml`: con 1133 pruebas seria una linea por cada
    | peticion de la suite, y ninguna de esas medidas significa nada.
    |
    */
    'medir_peticiones' => (bool) env('MEDIR_PETICIONES', true),

    /*
    | De 0 a 1. Con 0,1 se registra una peticion de cada diez y el fichero se
    | encoge a la decima parte CONSERVANDO la forma de la distribucion, asi
    | que los percentiles siguen siendo ciertos.
    |
    | No hay un umbral («registra solo lo que pase de 500 ms») a proposito:
    | eso censura la muestra por arriba y convierte el p50 en el percentil de
    | la cola. Un fichero mas pequenio no vale lo que vale un numero falso.
    */
    'muestreo' => (float) env('MEDIR_PETICIONES_MUESTREO', 1.0),

    /*
    | Por debajo de estas muestras, el informe NO imprime percentiles para esa
    | ruta: con cinco peticiones el «p99» es el maximo con otro nombre.
    */
    'minimo_para_percentil' => (int) env('MEDIR_PETICIONES_MINIMO', 20),

    /*
    |--------------------------------------------------------------------------
    | Salud de las colas
    |--------------------------------------------------------------------------
    |
    | Lo comprueba `colas:comprobar`, que corre a diario.
    |
    | `minutos_de_espera` es el que de verdad importa y por eso es bajo: lo que
    | se vigila no es que la cola este llena sino que AVANCE. Si el primero de
    | la fila lleva quince minutos sin que nadie lo atienda, no hay nadie
    | atendiendo la fila. Los trabajos de esta plataforma son avisos push y
    | trazados de ruta: se despachan en milisegundos.
    |
    | `maximo_esperando` es mas tolerante porque un numero alto si puede ser un
    | rato de trafico de verdad.
    |
    */
    'colas' => [
        'minutos_de_espera' => (int) env('COLAS_MINUTOS_DE_ESPERA', 15),
        'maximo_esperando' => (int) env('COLAS_MAXIMO_ESPERANDO', 500),
    ],

];
