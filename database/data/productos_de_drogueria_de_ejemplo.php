<?php

declare(strict_types=1);

/**
 * ==========================================================================
 * CUARENTA PRODUCTOS DE DROGUERÍA DE BARRIO, PARA PROBAR LA CARGA.
 *
 * Un array de PHP y no un `.xlsx` guardado en el repositorio: así se puede
 * revisar en un diff. Un binario commiteado se cambia y nadie ve qué cambió.
 * El Excel lo arma `php artisan drogueria:excel-de-ejemplo` con la MISMA
 * `PlantillaDeCatalogo` que baja el dueño del negocio, para que el ejemplo no
 * pueda desalinearse de la plantilla.
 *
 * --------------------------------------------------------------------------
 * EL CÓDIGO DE BARRAS VA VACÍO, Y NO ES UN OLVIDO.
 *
 * `products.barcode` tiene un índice ÚNICO --`products_barcode_unique`,
 * comprobado-- en el catálogo COMPARTIDO de toda la plataforma, con 5.586
 * códigos ya ocupados. Si este archivo trajera EANs inventados y alguien lo
 * subiera en producción --y lo va a subir, para eso se da-- esos números
 * quedarían tomados PARA SIEMPRE, y el producto de verdad nunca se encontraría
 * al escanearlo. Es el mismo fallo que `ImportadorDeCatalogo::leerFila` ya
 * tiene documentado para los códigos internos del punto de venta.
 *
 * El código es opcional, así que el archivo es válido sin él y de paso ejercita
 * el camino nombre + categoría, que es el que usa una droguería sin lector.
 *
 * Por lo mismo va vacío el REGISTRO INVIMA: inventar un número de registro
 * sanitario y meterlo al catálogo compartido es inventar un dato regulatorio.
 * El ejemplo de cómo se escribe está en la hoja «Instrucciones», que no se
 * importa nunca.
 *
 * --------------------------------------------------------------------------
 * Los precios son de 2026 en pesos colombianos, de droguería de barrio --no de
 * cadena con descuento--. Las existencias, las de un mostrador pequeño.
 *
 * --------------------------------------------------------------------------
 * LA CATEGORÍA ES UNA SUGERENCIA, NO UNA ORDEN.
 *
 * Medido al cargar estos 46 en «Droguería Vida Sana»: dos quedaron en otra
 * categoría --el Listerine en «Cuidado Personal» en vez de «Higiene oral», el
 * Vick en «Medicina» en vez de «Gripa y tos»--. No es un fallo: los dos ya
 * existían en el catálogo COMPARTIDO con `origen = 'equipo'`, el importador
 * los encontró por nombre y respetó la categoría que ya tenían.
 *
 * Es la conducta correcta: una tienda no puede recategorizar un producto para
 * todas las demás. La columna manda sólo cuando el producto es nuevo.
 *
 * [nombre, marca, categoría, precio, cantidad, presentación, contenido,
 *  requiere fórmula, principio activo, dosis]
 * ========================================================================== */

return [
    /* ---- Dolor y fiebre: lo que más se pide y sin fórmula ---- */
    ['Acetaminofén 500 mg', 'MK', 'Dolor y fiebre', 3800, 45, 'Caja', '10 tabletas', 'No', 'Acetaminofén', '500 mg'],
    ['Dolex Forte 500 mg', 'Dolex', 'Dolor y fiebre', 7200, 30, 'Caja', '12 tabletas', 'No', 'Acetaminofén', '500 mg'],
    ['Ibuprofeno 400 mg', 'MK', 'Dolor y fiebre', 5200, 38, 'Caja', '10 tabletas', 'No', 'Ibuprofeno', '400 mg'],
    ['Naproxeno 250 mg', 'Genfar', 'Dolor y fiebre', 6400, 22, 'Caja', '10 tabletas', 'No', 'Naproxeno', '250 mg'],
    ['Acetaminofén jarabe infantil', 'MK', 'Dolor y fiebre', 9800, 18, 'Frasco', '60 ml', 'No', 'Acetaminofén', '150 mg/5 ml'],
    ['Buscapina compuesta', 'Buscapina', 'Dolor y fiebre', 11500, 15, 'Caja', '10 tabletas', 'No', 'Butilbromuro de hioscina', '10 mg'],
    ['Diclofenaco 50 mg', 'Genfar', 'Dolor y fiebre', 4900, 26, 'Caja', '10 tabletas', 'No', 'Diclofenaco', '50 mg'],

    /* ---- Gripa y tos ---- */
    ['Noraver Gripa', 'Noraver', 'Gripa y tos', 10400, 25, 'Caja', '12 tabletas', 'No', '', ''],
    ['Dolex Gripa', 'Dolex', 'Gripa y tos', 9900, 24, 'Caja', '12 tabletas', 'No', '', ''],
    ['Jarabe para la tos Bisolvon', 'Bisolvon', 'Gripa y tos', 16800, 12, 'Frasco', '120 ml', 'No', 'Bromhexina', '4 mg/5 ml'],
    ['Pastillas para la garganta Strepsils', 'Strepsils', 'Gripa y tos', 8600, 20, 'Blíster', '12 pastillas', 'No', '', ''],
    ['Loratadina 10 mg', 'MK', 'Gripa y tos', 4200, 30, 'Caja', '10 tabletas', 'No', 'Loratadina', '10 mg'],
    ['Vick VapoRub', 'Vick', 'Gripa y tos', 14500, 16, 'Tarro', '50 g', 'No', '', ''],

    /* ---- Digestivo ---- */
    ['Omeprazol 20 mg', 'Genfar', 'Digestivo', 7800, 28, 'Caja', '14 cápsulas', 'No', 'Omeprazol', '20 mg'],
    ['Alka-Seltzer', 'Alka-Seltzer', 'Digestivo', 3600, 40, 'Sobre', '2 tabletas', 'No', '', ''],
    ['Sal de frutas Lua', 'Lua', 'Digestivo', 2800, 50, 'Sobre', '5 g', 'No', '', ''],
    ['Loperamida 2 mg', 'MK', 'Digestivo', 5600, 20, 'Caja', '12 cápsulas', 'No', 'Loperamida', '2 mg'],
    ['Suero oral Pedialyte 45', 'Pedialyte', 'Digestivo', 9900, 22, 'Botella', '500 ml', 'No', '', ''],
    ['Leche de magnesia Phillips', 'Phillips', 'Digestivo', 15200, 10, 'Frasco', '120 ml', 'No', '', ''],

    /* ---- Primeros auxilios: nada de esto es un medicamento ---- */
    ['Alcohol antiséptico', 'JGB', 'Primeros auxilios', 6500, 32, 'Frasco', '700 ml', 'No', '', ''],
    ['Agua oxigenada', 'JGB', 'Primeros auxilios', 4800, 25, 'Frasco', '120 ml', 'No', '', ''],
    ['Isodine solución', 'Isodine', 'Primeros auxilios', 13900, 14, 'Frasco', '120 ml', 'No', '', ''],
    ['Gasa estéril', 'Nipro', 'Primeros auxilios', 1500, 60, 'Sobre', '1 unidad', 'No', '', ''],
    ['Curitas surtidas', 'Curitas', 'Primeros auxilios', 7400, 28, 'Caja', '30 unidades', 'No', '', ''],
    ['Esparadrapo Micropore', '3M', 'Primeros auxilios', 5900, 20, 'Rollo', '1 unidad', 'No', '', ''],
    ['Jeringa desechable 5 ml', 'Nipro', 'Primeros auxilios', 1200, 80, 'Unidad', '1 unidad', 'No', '', ''],
    ['Algodón hidrófilo', 'Tecnoquímicas', 'Primeros auxilios', 5200, 24, 'Bolsa', '50 g', 'No', '', ''],

    /* ---- Vitaminas y suplementos ---- */
    ['Redoxon vitamina C', 'Redoxon', 'Vitaminas y suplementos', 18900, 15, 'Tubo', '10 tabletas', 'No', 'Ácido ascórbico', '1 g'],
    ['Centrum adultos', 'Centrum', 'Vitaminas y suplementos', 62000, 8, 'Frasco', '30 tabletas', 'No', '', ''],
    ['Ensure Advance vainilla', 'Ensure', 'Vitaminas y suplementos', 78000, 6, 'Tarro', '400 g', 'No', '', ''],
    ['Sulfato ferroso', 'MK', 'Vitaminas y suplementos', 6800, 18, 'Frasco', '120 ml', 'No', 'Sulfato ferroso', '125 mg/5 ml'],

    /* ---- Bebé ---- */
    ['Pañales Pequeñín etapa 3', 'Pequeñín', 'Bebé', 38500, 10, 'Paquete', '40 unidades', 'No', '', ''],
    ['Pañitos húmedos Pequeñín', 'Pequeñín', 'Bebé', 12400, 18, 'Paquete', '100 unidades', 'No', '', ''],
    ['Crema antipañalitis Desitin', 'Desitin', 'Bebé', 24900, 9, 'Tubo', '57 g', 'No', '', ''],

    /* ---- Higiene oral y dermocosmética ---- */
    ['Crema dental Colgate Total 12', 'Colgate', 'Higiene oral', 11200, 26, 'Tubo', '150 ml', 'No', '', ''],
    ['Enjuague bucal Listerine', 'Listerine', 'Higiene oral', 19800, 14, 'Frasco', '500 ml', 'No', '', ''],
    ['Protector solar Eucerin FPS 50', 'Eucerin', 'Dermocosmética', 86000, 5, 'Frasco', '50 ml', 'No', '', ''],
    ['Crema Cetaphil humectante', 'Cetaphil', 'Dermocosmética', 54000, 7, 'Frasco', '237 ml', 'No', '', ''],

    /* ---- Los que SÍ piden fórmula médica ----
       Cuatro, y son los que hacen que el distintivo valga: si el archivo de
       ejemplo no trajera ninguno, nadie vería nunca cómo se pinta. */
    ['Amoxicilina 500 mg', 'Genfar', 'Medicina', 9500, 20, 'Caja', '12 cápsulas', 'Sí', 'Amoxicilina', '500 mg'],
    ['Losartán 50 mg', 'MK', 'Medicina', 12000, 16, 'Caja', '30 tabletas', 'Sí', 'Losartán', '50 mg'],
    ['Metformina 850 mg', 'MK', 'Medicina', 8900, 18, 'Caja', '30 tabletas', 'Sí', 'Metformina', '850 mg'],
    ['Azitromicina 500 mg', 'Genfar', 'Medicina', 22400, 10, 'Caja', '3 tabletas', 'Sí', 'Azitromicina', '500 mg'],

    /* ---- Cuidado del adulto mayor y ortopedia ---- */
    ['Pañal para adulto Tena', 'Tena', 'Cuidado del adulto mayor', 42000, 8, 'Paquete', '8 unidades', 'No', '', ''],
    ['Termómetro digital', 'Citizen', 'Ortopedia y bienestar', 28500, 6, 'Unidad', '1 unidad', 'No', '', ''],
    ['Tensiómetro digital de brazo', 'Omron', 'Ortopedia y bienestar', 189000, 3, 'Unidad', '1 unidad', 'No', '', ''],
    ['Venda elástica 5 m', 'Nipro', 'Ortopedia y bienestar', 8900, 15, 'Rollo', '1 unidad', 'No', '', ''],
];
