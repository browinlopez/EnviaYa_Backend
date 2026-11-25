<?php

/*
 * Categorías revisadas producto por producto (2026-11-24) en la Tienda de
 * prueba y las dos droguerías Vida Sana. Las usa
 * `php artisan catalogo:ordenar-categorias`.
 *
 *  · `producto`: la categoría correcta DEL PRODUCTO, por nombre de categoría.
 *  · `fuera_de_drogueria`: alimentos que se colaron en el catálogo de la
 *    droguería (quesos, bebidas de arroz). Se sacan de los negocios de tipo
 *    droguería; el producto sigue existiendo para quien sí lo venda.
 *  · `en_tienda`: cuando una TIENDA vende un producto cuya categoría es de
 *    droguería, en esa tienda va en esta categoría suya.
 *
 * Se dejaron sin tocar los que no se pueden saber por el nombre («genfar
 * dol», «Avena Finesse», «Family», «Oliva»…): mejor como están que adivinados.
 */

return [
    'producto' => [
        // Tienda
        'Mascotas' => [234, 137],
        'Enlatados y salsas' => [479, 489, 490, 505, 506, 512, 480, 481, 484, 491, 492],
        'Ferretería y hogar' => [2, 457, 528],
        'Cerveza y licores' => [12],
        'Lácteos y Huevos' => [29],
        'Abarrotes' => [66, 564],
        'Snacks' => [169, 170],
        // Droguería
        'Bebé' => [7120, 7131, 7218, 7125, 7130, 7142, 7139, 7140, 7122, 7143, 7100, 7061, 7091],
        'Higiene oral' => [147],
        'Cuidado Personal' => [7106, 6984, 7003, 7000, 6989, 6971, 7008, 6975, 7080, 7004, 7077, 6881, 6904, 6906, 6912, 6892],
        'Medicina' => [7101, 7117, 5598],
        'Gripa y tos' => [6756, 562],
        'Vitaminas y suplementos' => [6797, 6799, 6810, 6811, 6802, 6743, 6748, 6771, 6778, 6780, 6777, 6774],
        'Dolor y fiebre' => [6737, 6744],
    ],

    'fuera_de_drogueria' => [6781, 6782, 6783, 6784, 6785, 6786, 6787, 6788, 6789, 6790, 6791],

    'en_tienda' => [
        'Cuidado Personal' => 'Cuidado personal e higiene',
        'Higiene oral' => 'Cuidado personal e higiene',
        'Bebé' => 'Cuidado personal e higiene',
        'Dermocosmética' => 'Cuidado personal e higiene',
        'Medicina' => 'Botiquín y medicamentos',
        'Dolor y fiebre' => 'Botiquín y medicamentos',
        'Gripa y tos' => 'Botiquín y medicamentos',
        'Digestivo' => 'Botiquín y medicamentos',
        'Primeros auxilios' => 'Botiquín y medicamentos',
        'Vitaminas y suplementos' => 'Botiquín y medicamentos',
        'Cuidado del adulto mayor' => 'Botiquín y medicamentos',
        'Ortopedia y bienestar' => 'Botiquín y medicamentos',
    ],
];
