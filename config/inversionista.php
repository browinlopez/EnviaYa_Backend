<?php

/*
 * EL PANEL DEL INVERSIONISTA.
 *
 * `ver_ingresos`: si el inversionista ve la PLATA TOTAL —ventas del periodo,
 * por tipo, por ciudad y mes a mes, el ingreso de la plataforma, las
 * comisiones y los subsidios— y el módulo de Ingresos.
 *
 * Apagado por defecto: por ahora el inversionista ve el crecimiento y los
 * PROMEDIOS (ticket promedio, pedidos por comprador, ventas por negocio que
 * vendió), no los totales. No se borra nada: con el interruptor encendido
 * todo vuelve como estaba, en las pantallas, el Excel y el PDF.
 *
 * Apagado, los totales NO SALEN del servidor (no es que la pantalla los
 * esconda) y `/v1/inversionista/ingresos` responde 403.
 */
return [
    'ver_ingresos' => (bool) env('INVERSIONISTA_VER_INGRESOS', false),
];
