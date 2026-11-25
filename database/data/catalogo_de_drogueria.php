<?php

declare(strict_types=1);

/**
 * ==========================================================================
 * EL MOSTRADOR DE UNA DROGUERÍA DE BARRIO COLOMBIANA.
 *
 * Doscientas referencias, que es el orden de magnitud de una droguería de
 * esquina de verdad: no las cinco mil de una cadena ni las cuarenta de una
 * prueba. Reemplaza al fichero de cuarenta y seis que sembraba el Excel de
 * ejemplo, que se queda para eso --enseñar cómo se llena-- mientras éste es
 * el catálogo.
 *
 * --------------------------------------------------------------------------
 * LO QUE AQUÍ ES CIERTO Y LO QUE NO.
 *
 * CIERTO: los nombres, las marcas, las presentaciones y los contenidos son
 * los de productos que se venden en Colombia, y «requiere fórmula» está
 * puesto según lo que la ley pide --antibióticos, antihipertensivos,
 * antidiabéticos y psicoactivos sí; analgésicos de venta libre no--.
 *
 * NO CIERTO, y hay que decirlo: los PRECIOS son verosímiles, no reales. Un
 * precio de droguería cambia por ciudad, por mes y por proveedor, y nadie
 * debería tomar éstos por una lista de precios.
 *
 * --------------------------------------------------------------------------
 * SIN CÓDIGO DE BARRAS, POR LO MISMO DE SIEMPRE.
 *
 * `products.barcode` es ÚNICO en el catálogo COMPARTIDO de toda la
 * plataforma. Inventar doscientos EANs los dejaría ocupados para siempre, y
 * el producto de verdad nunca se encontraría al escanearlo.
 *
 * Y SIN REGISTRO INVIMA, también por lo mismo: inventar un número de
 * registro sanitario es inventar un dato regulatorio.
 *
 * --------------------------------------------------------------------------
 * [nombre, marca, categoría, precio, presentación, contenido, fórmula,
 *  principio activo, dosis, frío]
 * ========================================================================== */

return [
    /* ================= DOLOR Y FIEBRE ================= */
    ['Acetaminofén 500 mg', 'MK', 'Dolor y fiebre', 3800, 'Caja', '10 tabletas', false, 'Acetaminofén', '500 mg', false],
    ['Acetaminofén 500 mg', 'Genfar', 'Dolor y fiebre', 3500, 'Caja', '10 tabletas', false, 'Acetaminofén', '500 mg', false],
    ['Dolex Forte', 'Dolex', 'Dolor y fiebre', 7200, 'Caja', '12 tabletas', false, 'Acetaminofén', '500 mg', false],
    ['Dolex Mujer', 'Dolex', 'Dolor y fiebre', 8400, 'Caja', '12 tabletas', false, 'Acetaminofén', '500 mg', false],
    ['Acetaminofén jarabe infantil', 'MK', 'Dolor y fiebre', 9800, 'Frasco', '60 ml', false, 'Acetaminofén', '150 mg/5 ml', false],
    ['Acetaminofén gotas pediátricas', 'Genfar', 'Dolor y fiebre', 8600, 'Frasco', '15 ml', false, 'Acetaminofén', '100 mg/ml', false],
    ['Ibuprofeno 400 mg', 'MK', 'Dolor y fiebre', 5200, 'Caja', '10 tabletas', false, 'Ibuprofeno', '400 mg', false],
    ['Ibuprofeno 600 mg', 'Genfar', 'Dolor y fiebre', 6400, 'Caja', '10 tabletas', false, 'Ibuprofeno', '600 mg', false],
    ['Advil', 'Advil', 'Dolor y fiebre', 11200, 'Caja', '10 cápsulas', false, 'Ibuprofeno', '400 mg', false],
    ['Naproxeno 250 mg', 'Genfar', 'Dolor y fiebre', 6400, 'Caja', '10 tabletas', false, 'Naproxeno', '250 mg', false],
    ['Naproxeno sódico 550 mg', 'MK', 'Dolor y fiebre', 8900, 'Caja', '10 tabletas', false, 'Naproxeno sódico', '550 mg', false],
    ['Diclofenaco 50 mg', 'Genfar', 'Dolor y fiebre', 4900, 'Caja', '10 tabletas', false, 'Diclofenaco', '50 mg', false],
    ['Diclofenaco gel', 'MK', 'Dolor y fiebre', 14500, 'Tubo', '30 g', false, 'Diclofenaco', '1 %', false],
    ['Voltaren Emulgel', 'Voltaren', 'Dolor y fiebre', 32000, 'Tubo', '60 g', false, 'Diclofenaco', '1 %', false],
    ['Buscapina compuesta', 'Buscapina', 'Dolor y fiebre', 11500, 'Caja', '10 tabletas', false, 'Butilbromuro de hioscina', '10 mg', false],
    ['Buscapina simple', 'Buscapina', 'Dolor y fiebre', 9800, 'Caja', '10 grageas', false, 'Butilbromuro de hioscina', '10 mg', false],
    ['Dolorán', 'Dolorán', 'Dolor y fiebre', 6800, 'Caja', '10 tabletas', false, 'Acetaminofén', '500 mg', false],
    ['Aspirina 500 mg', 'Bayer', 'Dolor y fiebre', 7900, 'Caja', '10 tabletas', false, 'Ácido acetilsalicílico', '500 mg', false],
    ['Aspirina 100 mg', 'Bayer', 'Dolor y fiebre', 9400, 'Caja', '30 tabletas', false, 'Ácido acetilsalicílico', '100 mg', false],
    ['Dolofin', 'La Santé', 'Dolor y fiebre', 5600, 'Caja', '10 tabletas', false, 'Ibuprofeno', '400 mg', false],
    ['Migranol', 'Memphis', 'Dolor y fiebre', 8200, 'Caja', '10 tabletas', false, '', '', false],
    ['Tramadol 50 mg', 'MK', 'Dolor y fiebre', 14800, 'Caja', '10 cápsulas', true, 'Tramadol', '50 mg', false],

    /* ================= GRIPA Y TOS ================= */
    ['Noraver Gripa', 'Noraver', 'Gripa y tos', 10400, 'Caja', '12 tabletas', false, '', '', false],
    ['Noraver Garganta miel-limón', 'Noraver', 'Gripa y tos', 7600, 'Blíster', '12 pastillas', false, '', '', false],
    ['Dolex Gripa', 'Dolex', 'Gripa y tos', 9900, 'Caja', '12 tabletas', false, '', '', false],
    ['Dolex Gripa Noche', 'Dolex', 'Gripa y tos', 10800, 'Caja', '12 tabletas', false, '', '', false],
    ['Nextday Gripa', 'Nextday', 'Gripa y tos', 8700, 'Caja', '12 tabletas', false, '', '', false],
    ['Sinutab', 'Sinutab', 'Gripa y tos', 12400, 'Caja', '12 tabletas', false, '', '', false],
    ['Bisolvon jarabe', 'Bisolvon', 'Gripa y tos', 16800, 'Frasco', '120 ml', false, 'Bromhexina', '4 mg/5 ml', false],
    ['Bisolvon Duo', 'Bisolvon', 'Gripa y tos', 19500, 'Frasco', '120 ml', false, '', '', false],
    ['Jarabe para la tos Ambroxol', 'MK', 'Gripa y tos', 11200, 'Frasco', '120 ml', false, 'Ambroxol', '30 mg/5 ml', false],
    ['Loratadina 10 mg', 'MK', 'Gripa y tos', 4200, 'Caja', '10 tabletas', false, 'Loratadina', '10 mg', false],
    ['Loratadina jarabe', 'Genfar', 'Gripa y tos', 9400, 'Frasco', '60 ml', false, 'Loratadina', '5 mg/5 ml', false],
    ['Cetirizina 10 mg', 'MK', 'Gripa y tos', 5800, 'Caja', '10 tabletas', false, 'Cetirizina', '10 mg', false],
    ['Desloratadina 5 mg', 'Genfar', 'Gripa y tos', 12600, 'Caja', '10 tabletas', false, 'Desloratadina', '5 mg', false],
    ['Strepsils miel-limón', 'Strepsils', 'Gripa y tos', 8600, 'Blíster', '12 pastillas', false, '', '', false],
    ['Vick VapoRub', 'Vick', 'Gripa y tos', 14500, 'Tarro', '50 g', false, '', '', false],
    ['Vick VapoRub grande', 'Vick', 'Gripa y tos', 24800, 'Tarro', '100 g', false, '', '', false],
    ['Vick Pyrena', 'Vick', 'Gripa y tos', 3200, 'Sobre', '1 unidad', false, '', '', false],
    ['Spray nasal solución salina', 'MK', 'Gripa y tos', 12900, 'Frasco', '30 ml', false, 'Cloruro de sodio', '0,9 %', false],
    ['Afrin nasal', 'Afrin', 'Gripa y tos', 18400, 'Frasco', '20 ml', false, 'Oximetazolina', '0,05 %', false],
    ['Miel de abejas con propóleo', 'Apicol', 'Gripa y tos', 15600, 'Frasco', '180 g', false, '', '', false],

    /* ================= DIGESTIVO ================= */
    ['Omeprazol 20 mg', 'Genfar', 'Digestivo', 7800, 'Caja', '14 cápsulas', false, 'Omeprazol', '20 mg', false],
    ['Omeprazol 20 mg', 'MK', 'Digestivo', 8400, 'Caja', '14 cápsulas', false, 'Omeprazol', '20 mg', false],
    ['Esomeprazol 40 mg', 'MK', 'Digestivo', 18600, 'Caja', '14 cápsulas', false, 'Esomeprazol', '40 mg', false],
    ['Ranitidina 300 mg', 'Genfar', 'Digestivo', 6900, 'Caja', '10 tabletas', false, 'Ranitidina', '300 mg', false],
    ['Alka-Seltzer', 'Alka-Seltzer', 'Digestivo', 3600, 'Sobre', '2 tabletas', false, '', '', false],
    ['Sal de frutas Lua', 'Lua', 'Digestivo', 2800, 'Sobre', '5 g', false, '', '', false],
    ['Sal de frutas Lua frasco', 'Lua', 'Digestivo', 16400, 'Frasco', '150 g', false, '', '', false],
    ['Leche de magnesia', 'Phillips', 'Digestivo', 15200, 'Frasco', '120 ml', false, 'Hidróxido de magnesio', '', false],
    ['Loperamida 2 mg', 'MK', 'Digestivo', 5600, 'Caja', '12 cápsulas', false, 'Loperamida', '2 mg', false],
    ['Suero oral Pedialyte 45', 'Pedialyte', 'Digestivo', 9900, 'Botella', '500 ml', false, '', '', false],
    ['Suero oral Pedialyte 60', 'Pedialyte', 'Digestivo', 10400, 'Botella', '500 ml', false, '', '', false],
    ['Suero oral Hidrasec', 'Hidrasec', 'Digestivo', 18900, 'Caja', '10 sobres', false, 'Racecadotrilo', '100 mg', false],
    ['Sales de rehidratación oral', 'MK', 'Digestivo', 2400, 'Sobre', '1 unidad', false, '', '', false],
    ['Enterogermina', 'Enterogermina', 'Digestivo', 32600, 'Caja', '10 ampollas', false, 'Bacillus clausii', '', false],
    ['Metoclopramida 10 mg', 'Genfar', 'Digestivo', 5400, 'Caja', '10 tabletas', false, 'Metoclopramida', '10 mg', false],
    ['Dimenhidrinato 50 mg', 'MK', 'Digestivo', 6200, 'Caja', '10 tabletas', false, 'Dimenhidrinato', '50 mg', false],
    ['Simeticona 80 mg', 'MK', 'Digestivo', 5900, 'Caja', '20 tabletas', false, 'Simeticona', '80 mg', false],
    ['Simeticona gotas', 'Genfar', 'Digestivo', 11400, 'Frasco', '15 ml', false, 'Simeticona', '100 mg/ml', false],
    ['Laxante Dulcolax', 'Dulcolax', 'Digestivo', 16800, 'Caja', '10 grageas', false, 'Bisacodilo', '5 mg', false],
    ['Agarol emulsión', 'Agarol', 'Digestivo', 21400, 'Frasco', '240 ml', false, '', '', false],
    ['Fibra Metamucil', 'Metamucil', 'Digestivo', 46800, 'Tarro', '283 g', false, 'Psyllium', '', false],

    /* ================= PRIMEROS AUXILIOS ================= */
    ['Alcohol antiséptico', 'JGB', 'Primeros auxilios', 6500, 'Frasco', '700 ml', false, '', '', false],
    ['Alcohol antiséptico', 'JGB', 'Primeros auxilios', 3200, 'Frasco', '350 ml', false, '', '', false],
    ['Agua oxigenada', 'JGB', 'Primeros auxilios', 4800, 'Frasco', '120 ml', false, 'Peróxido de hidrógeno', '3 %', false],
    ['Isodine solución', 'Isodine', 'Primeros auxilios', 13900, 'Frasco', '120 ml', false, 'Povidona yodada', '10 %', false],
    ['Isodine espuma', 'Isodine', 'Primeros auxilios', 18200, 'Frasco', '120 ml', false, 'Povidona yodada', '', false],
    ['Gasa estéril', 'Nipro', 'Primeros auxilios', 1500, 'Sobre', '1 unidad', false, '', '', false],
    ['Gasa estéril paquete', 'Nipro', 'Primeros auxilios', 9800, 'Paquete', '10 unidades', false, '', '', false],
    ['Curitas surtidas', 'Curitas', 'Primeros auxilios', 7400, 'Caja', '30 unidades', false, '', '', false],
    ['Curitas transparentes', 'Curitas', 'Primeros auxilios', 8100, 'Caja', '30 unidades', false, '', '', false],
    ['Esparadrapo Micropore', '3M', 'Primeros auxilios', 5900, 'Rollo', '1 unidad', false, '', '', false],
    ['Esparadrapo tela', 'Nipro', 'Primeros auxilios', 4600, 'Rollo', '1 unidad', false, '', '', false],
    ['Algodón hidrófilo', 'Tecnoquímicas', 'Primeros auxilios', 5200, 'Bolsa', '50 g', false, '', '', false],
    ['Algodón hidrófilo grande', 'Tecnoquímicas', 'Primeros auxilios', 12400, 'Bolsa', '200 g', false, '', '', false],
    ['Jeringa desechable 5 ml', 'Nipro', 'Primeros auxilios', 1200, 'Unidad', '1 unidad', false, '', '', false],
    ['Jeringa desechable 10 ml', 'Nipro', 'Primeros auxilios', 1500, 'Unidad', '1 unidad', false, '', '', false],
    ['Jeringa de insulina', 'Nipro', 'Primeros auxilios', 1800, 'Unidad', '1 unidad', false, '', '', false],
    ['Suero fisiológico', 'Baxter', 'Primeros auxilios', 8600, 'Frasco', '250 ml', false, 'Cloruro de sodio', '0,9 %', false],
    ['Guantes de látex', 'Nipro', 'Primeros auxilios', 1400, 'Unidad', '1 par', false, '', '', false],
    ['Tapabocas quirúrgico', 'Nipro', 'Primeros auxilios', 600, 'Unidad', '1 unidad', false, '', '', false],
    ['Tapabocas caja', 'Nipro', 'Primeros auxilios', 18900, 'Caja', '50 unidades', false, '', '', false],
    ['Aplicadores de algodón', 'Tecnoquímicas', 'Primeros auxilios', 4200, 'Bolsa', '100 unidades', false, '', '', false],
    ['Crema para quemaduras', 'Platsul-A', 'Primeros auxilios', 22800, 'Tubo', '30 g', false, 'Sulfadiazina de plata', '1 %', false],

    /* ================= VITAMINAS Y SUPLEMENTOS ================= */
    ['Redoxon vitamina C', 'Redoxon', 'Vitaminas y suplementos', 18900, 'Tubo', '10 tabletas', false, 'Ácido ascórbico', '1 g', false],
    ['Vitamina C 500 mg', 'MK', 'Vitaminas y suplementos', 9600, 'Caja', '30 tabletas', false, 'Ácido ascórbico', '500 mg', false],
    ['Centrum adultos', 'Centrum', 'Vitaminas y suplementos', 62000, 'Frasco', '30 tabletas', false, '', '', false],
    ['Centrum mujer', 'Centrum', 'Vitaminas y suplementos', 64500, 'Frasco', '30 tabletas', false, '', '', false],
    ['Pharmaton', 'Pharmaton', 'Vitaminas y suplementos', 78400, 'Frasco', '30 cápsulas', false, '', '', false],
    ['Ensure Advance vainilla', 'Ensure', 'Vitaminas y suplementos', 78000, 'Tarro', '400 g', false, '', '', false],
    ['Ensure Advance chocolate', 'Ensure', 'Vitaminas y suplementos', 78000, 'Tarro', '400 g', false, '', '', false],
    ['Glucerna vainilla', 'Glucerna', 'Vitaminas y suplementos', 92400, 'Tarro', '400 g', false, '', '', false],
    ['Sulfato ferroso jarabe', 'MK', 'Vitaminas y suplementos', 6800, 'Frasco', '120 ml', false, 'Sulfato ferroso', '125 mg/5 ml', false],
    ['Sulfato ferroso tabletas', 'Genfar', 'Vitaminas y suplementos', 5400, 'Caja', '30 tabletas', false, 'Sulfato ferroso', '300 mg', false],
    ['Ácido fólico 1 mg', 'MK', 'Vitaminas y suplementos', 4800, 'Caja', '30 tabletas', false, 'Ácido fólico', '1 mg', false],
    ['Calcio + vitamina D', 'MK', 'Vitaminas y suplementos', 16800, 'Frasco', '60 tabletas', false, '', '', false],
    ['Omega 3', 'MK', 'Vitaminas y suplementos', 34600, 'Frasco', '60 cápsulas', false, '', '', false],
    ['Complejo B', 'Genfar', 'Vitaminas y suplementos', 11400, 'Caja', '30 tabletas', false, '', '', false],
    ['Zinc 50 mg', 'MK', 'Vitaminas y suplementos', 14200, 'Frasco', '30 tabletas', false, 'Zinc', '50 mg', false],
    ['Vitamina D3 1000 UI', 'MK', 'Vitaminas y suplementos', 26800, 'Frasco', '30 cápsulas', false, 'Colecalciferol', '1000 UI', false],

    /* ================= BEBÉ ================= */
    ['Pañales Pequeñín etapa 2', 'Pequeñín', 'Bebé', 36400, 'Paquete', '44 unidades', false, '', '', false],
    ['Pañales Pequeñín etapa 3', 'Pequeñín', 'Bebé', 38500, 'Paquete', '40 unidades', false, '', '', false],
    ['Pañales Pequeñín etapa 4', 'Pequeñín', 'Bebé', 39800, 'Paquete', '36 unidades', false, '', '', false],
    ['Pañales Winny etapa 3', 'Winny', 'Bebé', 34900, 'Paquete', '40 unidades', false, '', '', false],
    ['Pañales Huggies etapa 3', 'Huggies', 'Bebé', 42600, 'Paquete', '40 unidades', false, '', '', false],
    ['Pañitos húmedos Pequeñín', 'Pequeñín', 'Bebé', 12400, 'Paquete', '100 unidades', false, '', '', false],
    ['Pañitos húmedos Huggies', 'Huggies', 'Bebé', 14800, 'Paquete', '80 unidades', false, '', '', false],
    ['Crema antipañalitis Desitin', 'Desitin', 'Bebé', 24900, 'Tubo', '57 g', false, 'Óxido de zinc', '', false],
    ['Crema antipañalitis Pequeñín', 'Pequeñín', 'Bebé', 14600, 'Tubo', '60 g', false, '', '', false],
    ['Shampoo Johnson bebé', 'Johnson', 'Bebé', 18400, 'Frasco', '400 ml', false, '', '', false],
    ['Aceite Johnson bebé', 'Johnson', 'Bebé', 16800, 'Frasco', '200 ml', false, '', '', false],
    ['Talco Johnson bebé', 'Johnson', 'Bebé', 12600, 'Frasco', '200 g', false, '', '', false],
    ['Fórmula infantil Nan 1', 'Nan', 'Bebé', 86400, 'Tarro', '400 g', false, '', '', false],
    ['Fórmula infantil Nan 2', 'Nan', 'Bebé', 84200, 'Tarro', '400 g', false, '', '', false],
    ['Compota de manzana', 'Gerber', 'Bebé', 4800, 'Frasco', '113 g', false, '', '', false],
    ['Compota de pera', 'Gerber', 'Bebé', 4800, 'Frasco', '113 g', false, '', '', false],
    ['Tetero anatómico', 'Avent', 'Bebé', 38900, 'Unidad', '260 ml', false, '', '', false],
    ['Chupo entretención', 'Avent', 'Bebé', 22400, 'Unidad', '1 unidad', false, '', '', false],

    /* ================= HIGIENE ORAL ================= */
    ['Crema dental Colgate Total 12', 'Colgate', 'Higiene oral', 11200, 'Tubo', '150 ml', false, '', '', false],
    ['Crema dental Colgate Triple Acción', 'Colgate', 'Higiene oral', 8400, 'Tubo', '150 ml', false, '', '', false],
    ['Crema dental Colgate Luminous White', 'Colgate', 'Higiene oral', 14600, 'Tubo', '100 ml', false, '', '', false],
    ['Crema dental Fortident', 'Fortident', 'Higiene oral', 5800, 'Tubo', '150 ml', false, '', '', false],
    ['Crema dental Sensodyne', 'Sensodyne', 'Higiene oral', 19800, 'Tubo', '100 g', false, '', '', false],
    ['Enjuague bucal Listerine Cool Mint', 'Listerine', 'Higiene oral', 19800, 'Frasco', '500 ml', false, '', '', false],
    ['Enjuague bucal Listerine Zero', 'Listerine', 'Higiene oral', 21400, 'Frasco', '500 ml', false, '', '', false],
    ['Enjuague bucal Colgate Plax', 'Colgate', 'Higiene oral', 16200, 'Frasco', '500 ml', false, '', '', false],
    ['Cepillo dental Colgate Twister', 'Colgate', 'Higiene oral', 7800, 'Unidad', '1 unidad', false, '', '', false],
    ['Cepillo dental Oral-B', 'Oral-B', 'Higiene oral', 9400, 'Unidad', '1 unidad', false, '', '', false],
    ['Cepillo dental infantil', 'Colgate', 'Higiene oral', 6900, 'Unidad', '1 unidad', false, '', '', false],
    ['Seda dental Oral-B', 'Oral-B', 'Higiene oral', 11600, 'Unidad', '50 m', false, '', '', false],
    ['Seda dental Colgate', 'Colgate', 'Higiene oral', 9800, 'Unidad', '50 m', false, '', '', false],
    ['Crema para prótesis Corega', 'Corega', 'Higiene oral', 28400, 'Tubo', '40 g', false, '', '', false],

    /* ================= DERMOCOSMÉTICA ================= */
    ['Protector solar Eucerin FPS 50', 'Eucerin', 'Dermocosmética', 86000, 'Frasco', '50 ml', false, '', '', false],
    ['Protector solar Nivea Sun FPS 50', 'Nivea', 'Dermocosmética', 42600, 'Frasco', '200 ml', false, '', '', false],
    ['Protector solar Umbrella FPS 50', 'Umbrella', 'Dermocosmética', 38400, 'Frasco', '120 ml', false, '', '', false],
    ['Crema Cetaphil humectante', 'Cetaphil', 'Dermocosmética', 54000, 'Frasco', '237 ml', false, '', '', false],
    ['Limpiador Cetaphil', 'Cetaphil', 'Dermocosmética', 48600, 'Frasco', '237 ml', false, '', '', false],
    ['Crema Nivea', 'Nivea', 'Dermocosmética', 18400, 'Tarro', '150 ml', false, '', '', false],
    ['Crema Lubriderm', 'Lubriderm', 'Dermocosmética', 32800, 'Frasco', '400 ml', false, '', '', false],
    ['Jabón Protex', 'Protex', 'Dermocosmética', 4200, 'Unidad', '110 g', false, '', '', false],
    ['Jabón Asepxia', 'Asepxia', 'Dermocosmética', 12400, 'Unidad', '100 g', false, '', '', false],
    ['Clotrimazol crema', 'MK', 'Dermocosmética', 9800, 'Tubo', '20 g', false, 'Clotrimazol', '1 %', false],
    ['Ketoconazol crema', 'Genfar', 'Dermocosmética', 12600, 'Tubo', '20 g', false, 'Ketoconazol', '2 %', false],
    ['Shampoo anticaspa Head & Shoulders', 'Head & Shoulders', 'Dermocosmética', 24800, 'Frasco', '375 ml', false, '', '', false],
    ['Hidrocortisona crema', 'MK', 'Dermocosmética', 11400, 'Tubo', '15 g', false, 'Hidrocortisona', '1 %', false],
    ['Caladryl loción', 'Caladryl', 'Dermocosmética', 21600, 'Frasco', '120 ml', false, '', '', false],

    /* ================= CUIDADO PERSONAL ================= */
    ['Toallas Nosotras Invisible', 'Nosotras', 'Cuidado Personal', 9800, 'Paquete', '10 unidades', false, '', '', false],
    ['Toallas Nosotras Normal', 'Nosotras', 'Cuidado Personal', 8400, 'Paquete', '10 unidades', false, '', '', false],
    ['Toallas Kotex', 'Kotex', 'Cuidado Personal', 9200, 'Paquete', '10 unidades', false, '', '', false],
    ['Protectores diarios Nosotras', 'Nosotras', 'Cuidado Personal', 7600, 'Paquete', '15 unidades', false, '', '', false],
    ['Tampones OB', 'OB', 'Cuidado Personal', 16400, 'Caja', '10 unidades', false, '', '', false],
    ['Desodorante Lady Speed Stick', 'Lady Speed Stick', 'Cuidado Personal', 14600, 'Unidad', '50 g', false, '', '', false],
    ['Desodorante Rexona hombre', 'Rexona', 'Cuidado Personal', 13800, 'Unidad', '50 g', false, '', '', false],
    ['Desodorante Yodora', 'Yodora', 'Cuidado Personal', 9400, 'Unidad', '32 g', false, '', '', false],
    ['Máquina de afeitar Gillette', 'Gillette', 'Cuidado Personal', 8600, 'Paquete', '2 unidades', false, '', '', false],
    ['Espuma de afeitar Gillette', 'Gillette', 'Cuidado Personal', 18400, 'Unidad', '175 g', false, '', '', false],
    ['Condones Today', 'Today', 'Cuidado Personal', 11800, 'Caja', '3 unidades', false, '', '', false],
    ['Condones Durex', 'Durex', 'Cuidado Personal', 18600, 'Caja', '3 unidades', false, '', '', false],
    ['Prueba de embarazo', 'Evatest', 'Cuidado Personal', 16800, 'Unidad', '1 unidad', false, '', '', false],
    ['Shampoo Sedal', 'Sedal', 'Cuidado Personal', 16400, 'Frasco', '340 ml', false, '', '', false],
    ['Papel higiénico Familia x4', 'Familia', 'Cuidado Personal', 11200, 'Paquete', '4 rollos', false, '', '', false],

    /* ================= CUIDADO DEL ADULTO MAYOR ================= */
    ['Pañal para adulto Tena M', 'Tena', 'Cuidado del adulto mayor', 42000, 'Paquete', '8 unidades', false, '', '', false],
    ['Pañal para adulto Tena L', 'Tena', 'Cuidado del adulto mayor', 44800, 'Paquete', '8 unidades', false, '', '', false],
    ['Pañal para adulto Plenitud', 'Plenitud', 'Cuidado del adulto mayor', 38600, 'Paquete', '8 unidades', false, '', '', false],
    ['Protector de cama desechable', 'Tena', 'Cuidado del adulto mayor', 28400, 'Paquete', '10 unidades', false, '', '', false],
    ['Pañitos húmedos para adulto', 'Tena', 'Cuidado del adulto mayor', 16800, 'Paquete', '48 unidades', false, '', '', false],
    ['Crema para escaras', 'Tena', 'Cuidado del adulto mayor', 34600, 'Tubo', '100 ml', false, '', '', false],
    ['Pastillero semanal', 'Genérico', 'Cuidado del adulto mayor', 12400, 'Unidad', '1 unidad', false, '', '', false],

    /* ================= ORTOPEDIA Y BIENESTAR ================= */
    ['Termómetro digital', 'Citizen', 'Ortopedia y bienestar', 28500, 'Unidad', '1 unidad', false, '', '', false],
    ['Termómetro infrarrojo', 'Omron', 'Ortopedia y bienestar', 86400, 'Unidad', '1 unidad', false, '', '', false],
    ['Tensiómetro digital de brazo', 'Omron', 'Ortopedia y bienestar', 189000, 'Unidad', '1 unidad', false, '', '', false],
    ['Tensiómetro de muñeca', 'Omron', 'Ortopedia y bienestar', 142000, 'Unidad', '1 unidad', false, '', '', false],
    ['Glucómetro OneTouch', 'OneTouch', 'Ortopedia y bienestar', 98600, 'Unidad', '1 unidad', false, '', '', false],
    ['Tirillas OneTouch', 'OneTouch', 'Ortopedia y bienestar', 86400, 'Caja', '50 unidades', false, '', '', false],
    ['Lancetas para glucómetro', 'OneTouch', 'Ortopedia y bienestar', 24800, 'Caja', '100 unidades', false, '', '', false],
    ['Venda elástica 5 m', 'Nipro', 'Ortopedia y bienestar', 8900, 'Rollo', '1 unidad', false, '', '', false],
    ['Venda de yeso', 'Nipro', 'Ortopedia y bienestar', 12400, 'Rollo', '1 unidad', false, '', '', false],
    ['Tobillera elástica', 'Dr. Scholl', 'Ortopedia y bienestar', 32600, 'Unidad', '1 unidad', false, '', '', false],
    ['Rodillera elástica', 'Dr. Scholl', 'Ortopedia y bienestar', 38400, 'Unidad', '1 unidad', false, '', '', false],
    ['Muñequera elástica', 'Dr. Scholl', 'Ortopedia y bienestar', 28900, 'Unidad', '1 unidad', false, '', '', false],
    ['Faja lumbar', 'Dr. Scholl', 'Ortopedia y bienestar', 64800, 'Unidad', '1 unidad', false, '', '', false],
    ['Plantillas de gel', 'Dr. Scholl', 'Ortopedia y bienestar', 36400, 'Paquete', '1 par', false, '', '', false],
    ['Bastón ajustable', 'Genérico', 'Ortopedia y bienestar', 48600, 'Unidad', '1 unidad', false, '', '', false],
    ['Nebulizador', 'Omron', 'Ortopedia y bienestar', 168000, 'Unidad', '1 unidad', false, '', '', false],

    /* ================= MEDICINA (lo que pide fórmula) =================
       Acá va lo que la ley colombiana exige recetar: antibióticos,
       antihipertensivos, antidiabéticos y psicoactivos. Es lo que hace que
       el distintivo de «requiere fórmula» valga para algo: si el catálogo no
       trajera ninguno, nadie vería nunca cómo se pinta. */
    ['Amoxicilina 500 mg', 'Genfar', 'Medicina', 9500, 'Caja', '12 cápsulas', true, 'Amoxicilina', '500 mg', false],
    ['Amoxicilina suspensión', 'MK', 'Medicina', 14600, 'Frasco', '60 ml', true, 'Amoxicilina', '250 mg/5 ml', false],
    ['Amoxicilina + ácido clavulánico', 'MK', 'Medicina', 28400, 'Caja', '10 tabletas', true, 'Amoxicilina', '875 mg', false],
    ['Azitromicina 500 mg', 'Genfar', 'Medicina', 22400, 'Caja', '3 tabletas', true, 'Azitromicina', '500 mg', false],
    ['Cefalexina 500 mg', 'MK', 'Medicina', 18600, 'Caja', '12 cápsulas', true, 'Cefalexina', '500 mg', false],
    ['Ciprofloxacino 500 mg', 'Genfar', 'Medicina', 16800, 'Caja', '10 tabletas', true, 'Ciprofloxacino', '500 mg', false],
    ['Doxiciclina 100 mg', 'MK', 'Medicina', 14200, 'Caja', '10 cápsulas', true, 'Doxiciclina', '100 mg', false],
    ['Metronidazol 500 mg', 'Genfar', 'Medicina', 9800, 'Caja', '10 tabletas', true, 'Metronidazol', '500 mg', false],
    ['Losartán 50 mg', 'MK', 'Medicina', 12000, 'Caja', '30 tabletas', true, 'Losartán', '50 mg', false],
    ['Losartán 100 mg', 'Genfar', 'Medicina', 16400, 'Caja', '30 tabletas', true, 'Losartán', '100 mg', false],
    ['Enalapril 20 mg', 'MK', 'Medicina', 7400, 'Caja', '30 tabletas', true, 'Enalapril', '20 mg', false],
    ['Amlodipino 5 mg', 'Genfar', 'Medicina', 8900, 'Caja', '30 tabletas', true, 'Amlodipino', '5 mg', false],
    ['Hidroclorotiazida 25 mg', 'MK', 'Medicina', 6800, 'Caja', '30 tabletas', true, 'Hidroclorotiazida', '25 mg', false],
    ['Metformina 850 mg', 'MK', 'Medicina', 8900, 'Caja', '30 tabletas', true, 'Metformina', '850 mg', false],
    ['Metformina 1000 mg', 'Genfar', 'Medicina', 11400, 'Caja', '30 tabletas', true, 'Metformina', '1000 mg', false],
    ['Glibenclamida 5 mg', 'MK', 'Medicina', 7200, 'Caja', '30 tabletas', true, 'Glibenclamida', '5 mg', false],
    ['Atorvastatina 20 mg', 'Genfar', 'Medicina', 14800, 'Caja', '30 tabletas', true, 'Atorvastatina', '20 mg', false],
    ['Levotiroxina 50 mcg', 'MK', 'Medicina', 12600, 'Caja', '30 tabletas', true, 'Levotiroxina', '50 mcg', false],
    ['Prednisolona 5 mg', 'Genfar', 'Medicina', 9400, 'Caja', '20 tabletas', true, 'Prednisolona', '5 mg', false],
    ['Salbutamol inhalador', 'MK', 'Medicina', 24600, 'Inhalador', '200 dosis', true, 'Salbutamol', '100 mcg', false],
    ['Insulina NPH', 'Humulin', 'Medicina', 68400, 'Frasco', '10 ml', true, 'Insulina humana', '100 UI/ml', true],
    ['Insulina glargina', 'Lantus', 'Medicina', 128000, 'Frasco', '10 ml', true, 'Insulina glargina', '100 UI/ml', true],
];
