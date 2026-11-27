<?php

declare(strict_types=1);

namespace Database\Seeders\DemostracionQa;

// El libreto de la demostración: quiénes son, dónde quedan y qué venden. Solo datos.
final class Guion
{
    // Barrios de los compradores: [municipio, lat, lng, cuántos, barrio, calles, carreras].
    public const ZONAS = [
        'villa_santos' => ['Barranquilla', 11.0112, -74.8290, 10, 'Villa Santos', [94, 106], [46, 56]],
        'riomar' => ['Barranquilla', 11.0158, -74.8210, 9, 'Riomar', [100, 112], [49, 58]],
        'alto_prado' => ['Barranquilla', 11.0032, -74.8108, 9, 'Alto Prado', [76, 88], [42, 52]],
        'el_recreo' => ['Barranquilla', 10.9935, -74.8118, 8, 'El Recreo', [62, 74], [44, 54]],
        'villa_estadio' => ['Soledad', 10.9122, -74.7738, 11, 'Villa Estadio', [28, 40], [14, 22]],
        'soledad_centro' => ['Soledad', 10.9195, -74.7640, 11, 'Centro', [18, 30], [20, 32]],
        'soledad_2000' => ['Soledad', 10.9048, -74.7862, 10, 'Soledad 2000', [55, 70], [6, 16]],
        'malambo_centro' => ['Malambo', 10.8592, -74.7742, 9, 'Centro', [8, 18], [4, 14]],
        'caracoli' => ['Malambo', 10.8672, -74.7702, 7, 'Caracolí', [20, 32], [10, 20]],
        'campestre' => ['Puerto Colombia', 11.0195, -74.8585, 4, 'Villa Campestre', [1, 8], [1, 10]],
        'galapa' => ['Galapa', 10.8992, -74.8862, 6, 'Centro', [10, 20], [14, 24]],
    ];

    // Los residentes del conjunto de demostración (Puerto Colombia, sin negocios propios).
    public const RESIDENTES = 26;

    public const CONJUNTO = [
        'nombre' => 'Conjunto Residencial Torres de Villa Campestre',
        'direccion' => 'Km 2 vía al mar, Villa Campestre, Puerto Colombia',
        'municipio' => 'Puerto Colombia',
        'lat' => 11.0165,
        'lng' => -74.8560,
        'telefono' => '605 385 2210',
        'nit' => 'DEMOQA-901555123-4',
        'torres' => 4,
        'pisos' => 10,
        'por_piso' => 4,
        'alta' => 120,
    ];

    // [nombre, teléfono, cédula]
    public const DUENOS = [
        'jorge' => ['Jorge Luis Fontalvo', '300 412 7781', '1045821336'],
        'yolanda' => ['Yolanda Ospino', '301 587 2290', '22654871'],
        'wilmer' => ['Wilmer Pertuz', '304 219 6645', '72198453'],
        'luzdary' => ['Luz Dary Cantillo', '310 662 4108', '32876190'],
    ];

    // alta y arranque en días; diarios = pedidos por día ya maduro; zonas => afinidad.
    public const NEGOCIOS = [
        'fontalvo' => [
            'nombre' => 'Tienda Donde Fontalvo', 'oficio' => 'tienda', 'municipio' => 'Barranquilla',
            'lat' => 11.0106, 'lng' => -74.8262, 'direccion' => 'Calle 99 #49C-35, Villa Santos',
            'telefono' => '300 412 7781', 'dueno' => 'jorge', 'alta' => 319, 'arranque' => 3,
            'diarios' => 4.5, 'promesa' => 30, 'nit' => 'DEMOQA-1045821336-1',
            'zonas' => ['villa_santos' => 1.0, 'riomar' => 0.9, 'alto_prado' => 0.5, 'conjunto' => 1.0],
            'descripcion' => 'Tienda de barrio en Villa Santos: abarrotes, lácteos, bebidas frías y aseo.',
        ],
        'bendicion' => [
            'nombre' => 'Minimercado La Bendición', 'oficio' => 'tienda', 'municipio' => 'Soledad',
            'lat' => 10.9128, 'lng' => -74.7722, 'direccion' => 'Calle 32 #18-45, Villa Estadio',
            'telefono' => '301 587 2290', 'dueno' => 'yolanda', 'alta' => 257, 'arranque' => 2,
            'diarios' => 4.0, 'promesa' => 30, 'nit' => 'DEMOQA-22654871-5',
            'zonas' => ['villa_estadio' => 1.0, 'soledad_2000' => 1.0, 'soledad_centro' => 0.5],
            'descripcion' => 'Minimercado familiar en Soledad: mercado completo, carnes frías y domicilio.',
        ],
        'santa_rita' => [
            'nombre' => 'Droguería Santa Rita', 'oficio' => 'drogueria', 'municipio' => 'Soledad',
            'lat' => 10.9200, 'lng' => -74.7655, 'direccion' => 'Carrera 22 #24-10, Centro de Soledad',
            'telefono' => '300 412 7790', 'dueno' => 'jorge', 'alta' => 201, 'arranque' => 6,
            'diarios' => 3.0, 'promesa' => 30, 'nit' => 'DEMOQA-1045821336-2',
            'zonas' => ['soledad_centro' => 1.0, 'villa_estadio' => 0.7, 'soledad_2000' => 0.5],
            'descripcion' => 'Droguería de barrio con medicamentos, cuidado personal y bebés.',
        ],
        'la30' => [
            'nombre' => 'Repuestos y Lubricantes La 30', 'oficio' => 'repuestos',
            'municipio' => 'Malambo', 'lat' => 10.8640, 'lng' => -74.7765, 'direccion' => 'Calle 30 #12-56, Malambo',
            'telefono' => '304 219 6645', 'dueno' => 'wilmer', 'alta' => 160, 'arranque' => 12,
            'diarios' => 1.5, 'promesa' => 40, 'nit' => 'DEMOQA-72198453-0',
            'zonas' => ['malambo_centro' => 1.0, 'caracoli' => 1.0, 'soledad_2000' => 0.3, 'villa_estadio' => 0.2],
            'descripcion' => 'Repuestos para moto y carro, aceites y lubricantes sobre la Calle 30.',
        ],
        'sabor' => [
            'nombre' => 'Asadero El Sabor de Mamá', 'oficio' => 'restaurante', 'municipio' => 'Barranquilla',
            'lat' => 11.0040, 'lng' => -74.8130, 'direccion' => 'Carrera 46 #84-62, Alto Prado',
            'telefono' => '300 412 7795', 'dueno' => 'jorge', 'alta' => 111, 'arranque' => 1,
            'diarios' => 5.0, 'promesa' => 45, 'nit' => 'DEMOQA-1045821336-3',
            'zonas' => ['alto_prado' => 1.0, 'el_recreo' => 1.0, 'villa_santos' => 0.6, 'riomar' => 0.6, 'conjunto' => 0.9],
            'descripcion' => 'Comida casera costeña: corrientazo, sancocho, pollo asado y jugos naturales.',
        ],
        'farmavida' => [
            'nombre' => 'Droguería Farmavida Malambo', 'oficio' => 'drogueria', 'municipio' => 'Malambo',
            'lat' => 10.8575, 'lng' => -74.7728, 'direccion' => 'Carrera 9 #11-30, Centro de Malambo',
            'telefono' => '310 662 4108', 'dueno' => 'luzdary', 'alta' => 60, 'arranque' => 33,
            'diarios' => 2.5, 'promesa' => 30, 'nit' => 'DEMOQA-32876190-8',
            'zonas' => ['malambo_centro' => 1.0, 'caracoli' => 0.8],
            'descripcion' => 'Droguería en el centro de Malambo, con domicilio en el municipio.',
        ],
        'chava' => [
            'nombre' => 'Fritos y Arepas Doña Chava', 'oficio' => 'restaurante', 'municipio' => 'Soledad',
            'lat' => 10.9082, 'lng' => -74.7790, 'direccion' => 'Calle 45 #10-22, Soledad',
            'telefono' => '310 662 4115', 'dueno' => 'luzdary', 'alta' => 6, 'arranque' => 0,
            'diarios' => 0.0, 'promesa' => 40, 'nit' => 'DEMOQA-32876190-9',
            'zonas' => [],
            'descripcion' => 'Arepas de huevo, carimañolas y fritos costeños. Recién llegados a la app.',
        ],
    ];

    // [nombre, cédula, negocios, disponible, ciudad del contrato]
    public const DOMICILIARIOS = [
        ['Brayan Castro', '1043987211', ['fontalvo', 'sabor'], 1, 'Barranquilla'],
        ['Kevin Mercado', '1048276530', ['fontalvo', 'sabor'], 1, 'Barranquilla'],
        ['Deivis Polo', '1140852367', ['sabor'], 0, 'Barranquilla'],
        ['Yeison Barrios', '1042455189', ['fontalvo', 'sabor', 'santa_rita'], 1, 'Barranquilla'],
        ['Jhonatan Ariza', '1083012745', ['bendicion', 'santa_rita'], 1, 'Soledad'],
        ['Edinson Rada', '1129573018', ['bendicion', 'santa_rita', 'chava'], 0, 'Soledad'],
        ['Luis Ángel Orozco', '1001876234', ['la30', 'farmavida'], 1, 'Malambo'],
        ['Camilo Suárez', '1050963327', ['farmavida', 'la30', 'bendicion'], 1, 'Malambo'],
    ];

    // La administradora del conjunto (rol 5) y los celadores (rol 6), con su teléfono.
    public const ADMINISTRADORA = ['Patricia Mendoza', '300 385 2211'];

    public const CELADORES = [
        ['Ramiro Charris', '301 385 2201'],
        ['Yesenia Molina', '301 385 2202'],
        ['Hernando Villa', '301 385 2203'],
    ];

    public const NOMBRES = [
        'Laura', 'Jorge', 'Katherine', 'Emilio', 'Sofía', 'Yulieth', 'Carlos', 'Mónica', 'Andrés', 'Liliana',
        'Kelly', 'Jhon Jairo', 'Paola', 'Hernán', 'Estefanía', 'Rafael', 'Nayibe', 'Luis Fernando', 'Diana',
        'Álvaro', 'Yesenia', 'Wilson', 'Rosa', 'Fabián', 'Mariana', 'Iván', 'Daniela', 'Alberto', 'Andrea',
        'Gustavo', 'Melissa', 'Óscar', 'Valentina', 'Germán', 'Shirley', 'Mauricio', 'Tatiana', 'Ricardo',
        'Lorena', 'Kevin', 'Natalia', 'Camilo', 'Ingrid', 'Sebastián', 'Yolanda', 'Eduardo', 'Milena', 'Jesús',
    ];

    public const APELLIDOS = [
        'Mendoza', 'Barrios', 'Ariza', 'Zúñiga', 'Herrera', 'Charris', 'Ahumada', 'Pacheco', 'De la Hoz',
        'Mercado', 'Rada', 'Salcedo', 'Villa', 'Rúa', 'Caballero', 'Torres', 'Bolaño', 'Pérez', 'Navarro',
        'Coronado', 'Tamara', 'Iriarte', 'Movilla', 'Vengoechea', 'Padilla', 'Consuegra', 'Cantillo', 'Rojas',
        'Peñaranda', 'Maestre', 'Sarmiento', 'Vásquez', 'Torregroza', 'Barraza', 'Pineda', 'Altamar',
        'Cassiani', 'Lozano', 'Palencia', 'Polo', 'Cueto', 'Fuentes', 'Arrieta', 'Escorcia', 'Orozco', 'Molina',
    ];

    // Surtido propio por oficio (el catálogo general es de tienda): [nombre, precio, grupo, peso].
    public const SURTIDOS = [
        'drogueria' => [
            ['Acetaminofén 500 mg x 10 tabletas', 2500, 'general', 30], ['Dolex Forte x 10 tabletas', 9800, 'general', 18],
            ['Ibuprofeno 400 mg x 10 tabletas', 4500, 'general', 16], ['Advil Max x 10 cápsulas', 12500, 'general', 9],
            ['Loratadina 10 mg x 10 tabletas', 3500, 'general', 10], ['Omeprazol 20 mg x 14 cápsulas', 6500, 'general', 11],
            ['Sal de frutas Lua sobre', 1800, 'general', 12], ['Alka-Seltzer x 12 tabletas', 9900, 'general', 7],
            ['Suero oral Pedialyte 500 ml', 8900, 'general', 13], ['Electrolit 625 ml', 7500, 'general', 12],
            ['Vick VapoRub 50 g', 13900, 'general', 6], ['Pañales etapa 3 x 30', 42900, 'general', 9],
            ['Pañitos húmedos x 50', 9800, 'general', 8], ['Crema antipañalitis 60 g', 15900, 'general', 5],
            ['Alcohol antiséptico 350 ml', 5900, 'general', 8], ['Gasa estéril x 10', 3200, 'general', 4],
            ['Curitas x 30', 6500, 'general', 6], ['Tapabocas x 10', 5000, 'general', 5],
            ['Termómetro digital', 18900, 'general', 2], ['Protector solar FPS 50 120 ml', 48900, 'general', 3],
            ['Vitamina C 500 mg x 30', 15900, 'vitaminas', 7], ['Complejo B x 30 tabletas', 12900, 'vitaminas', 5],
            ['Multivitamínico x 30 tabletas', 38900, 'vitaminas', 4], ['Suplemento nutricional vainilla 400 g', 52900, 'vitaminas', 3],
            ['Crema dental triple acción 75 ml', 6900, 'general', 9], ['Cepillo dental medio', 4900, 'general', 5],
            ['Enjuague bucal 500 ml', 17900, 'general', 4], ['Toallas higiénicas x 10', 5500, 'general', 8],
            ['Jarabe para la tos 120 ml', 16900, 'general', 6], ['Metformina 850 mg x 30', 9500, 'general', 5],
            ['Losartán 50 mg x 30 tabletas', 8900, 'general', 6], ['Naproxeno 250 mg x 10', 4900, 'general', 5],
            ['Desodorante roll-on 50 ml', 9900, 'general', 5], ['Algodón 50 g', 3500, 'general', 3],
            ['Agua oxigenada 120 ml', 2900, 'general', 3], ['Prueba de embarazo', 8900, 'general', 2],
        ],
        'restaurante' => [
            ['Corrientazo del día', 14000, 'almuerzo', 30], ['Bandeja paisa', 26000, 'almuerzo', 16],
            ['Sancocho de gallina', 18000, 'almuerzo', 16], ['Arroz con pollo', 16000, 'almuerzo', 15],
            ['Mojarra frita con patacón', 28000, 'almuerzo', 10], ['Mondongo', 17000, 'almuerzo', 8],
            ['Cazuela de mariscos', 34000, 'almuerzo', 5], ['Pollo asado entero', 38000, 'cena', 12],
            ['Medio pollo asado', 21000, 'cena', 14], ['Chuzo de res', 15000, 'cena', 10],
            ['Hamburguesa sencilla', 15000, 'cena', 13], ['Perro caliente', 9000, 'cena', 12],
            ['Patacón con todo', 18000, 'cena', 10], ['Arepa de huevo', 5000, 'desayuno', 12],
            ['Carimañola de queso', 3000, 'desayuno', 9], ['Calentado con huevo', 12000, 'desayuno', 10],
            ['Huevos pericos con arepa', 9000, 'desayuno', 8], ['Caldo de costilla', 10000, 'desayuno', 7],
            ['Jugo natural en agua', 4500, 'bebida', 20], ['Jugo de lulo en leche', 6000, 'bebida', 8],
            ['Limonada de coco', 8000, 'bebida', 12], ['Gaseosa personal', 3500, 'bebida', 16],
            ['Gaseosa 1.5 litros', 7000, 'bebida', 6], ['Flan de caramelo', 6000, 'postre', 6],
            ['Tres leches', 7500, 'postre', 7], ['Arroz con leche', 5000, 'postre', 5],
            ['Porción de patacón', 4000, 'adicional', 8], ['Porción de yuca frita', 4000, 'adicional', 6],
            ['Porción de arroz', 3000, 'adicional', 5],
        ],
        'repuestos' => [
            ['Aceite 4T 20W-50 1/4 de galón', 28000, 'general', 30], ['Aceite de motor 10W-30 1/4 de galón', 32000, 'general', 12],
            ['Filtro de aceite para moto', 15000, 'general', 16], ['Filtro de aire para moto', 22000, 'general', 9],
            ['Bujía de encendido', 12000, 'general', 14], ['Kit de arrastre (cadena, piñón y corona)', 115000, 'general', 6],
            ['Pastillas de freno delanteras moto', 28000, 'general', 11], ['Bombillo de farola H4', 15000, 'general', 8],
            ['Batería de moto 12V 7Ah', 145000, 'general', 4], ['Llanta 90/90-18 para moto', 135000, 'general', 4],
            ['Guaya de clutch', 14000, 'general', 7], ['Guaya de acelerador', 12000, 'general', 6],
            ['Líquido de frenos DOT 4 250 ml', 16000, 'general', 6], ['Refrigerante 1 litro', 18000, 'general', 5],
            ['Limpiador de carburador', 21000, 'general', 5], ['Grasa multipropósito 500 g', 15000, 'general', 4],
            ['Espejos retrovisores para moto (par)', 32000, 'general', 5], ['Manigueta de freno', 18000, 'general', 5],
            ['Direccionales LED (par)', 35000, 'general', 4], ['Casco certificado', 185000, 'general', 2],
            ['Plumillas limpiabrisas 20 pulgadas', 25000, 'general', 3], ['Filtro de aceite para carro', 25000, 'general', 4],
            ['Aditivo para gasolina', 19000, 'general', 3], ['Cinta aislante', 4000, 'general', 4],
        ],
        'tienda' => [
            ['Arroz 500 g', 2900, 'general', 30], ['Aceite de cocina 1 litro', 11900, 'general', 14],
            ['Huevos AA x 30', 18500, 'general', 16], ['Leche entera 1 litro', 4200, 'general', 22],
            ['Pan tajado', 6500, 'general', 15], ['Café molido 250 g', 9800, 'general', 10],
            ['Azúcar 1 kg', 5200, 'general', 12], ['Panela 1000 g', 4500, 'general', 8],
            ['Gaseosa 1.5 litros', 6800, 'general', 18], ['Agua 600 ml', 1800, 'general', 10],
            ['Malta 330 ml', 2500, 'general', 12], ['Cerveza lata 330 ml', 3300, 'general', 14],
            ['Queso costeño 250 g', 7500, 'general', 12], ['Salchichón 450 g', 3500, 'general', 8],
            ['Atún en lata 160 g', 7900, 'general', 7], ['Pasta spaghetti 250 g', 2600, 'general', 8],
            ['Lentejas 500 g', 4200, 'general', 6], ['Fríjol rojo 500 g', 6900, 'general', 5],
            ['Jabón de barra para ropa', 3500, 'general', 7], ['Detergente en polvo 1 kg', 14900, 'general', 6],
            ['Papel higiénico x 4', 7900, 'general', 9], ['Galletas de chocolate', 3800, 'general', 7],
            ['Papas fritas 45 g', 2500, 'general', 9], ['Mantequilla 250 g', 6900, 'general', 5],
            ['Avena en hojuelas 500 g', 6500, 'general', 4], ['Bolsa de hielo', 2500, 'general', 6],
            ['Plátano verde x 3', 3000, 'general', 8], ['Tomate chonto 1 libra', 2500, 'general', 7],
            ['Cebolla cabezona 1 libra', 2200, 'general', 6], ['Chocolatina', 1200, 'general', 6],
        ],
    ];

    // Gastos fijos por oficio: [tipo, nombre, monto, frecuencia].
    public const GASTOS = [
        'tienda' => [
            ['arriendo', 'Arriendo del local', 950000, 'mensual'], ['luz', 'Luz (con las neveras)', 210000, 'mensual'],
            ['agua', 'Agua', 48000, 'mensual'], ['internet', 'Internet y celular', 75000, 'mensual'],
            ['empleados', 'Ayudante de mostrador (medio tiempo)', 750000, 'mensual'],
            ['transporte', 'Flete del surtido semanal', 120000, 'mensual'],
        ],
        'drogueria' => [
            ['arriendo', 'Arriendo del local', 1600000, 'mensual'], ['luz', 'Luz (con el aire y la nevera)', 230000, 'mensual'],
            ['agua', 'Agua', 55000, 'mensual'], ['internet', 'Internet y datáfono', 95000, 'mensual'],
            ['empleados', 'Auxiliar de farmacia', 1650000, 'mensual'],
            ['otro', 'Programa de inventario y facturación', 89000, 'mensual'],
        ],
        'restaurante' => [
            ['arriendo', 'Arriendo del local', 1500000, 'mensual'], ['luz', 'Luz', 360000, 'mensual'],
            ['agua', 'Agua', 125000, 'mensual'], ['gas', 'Gas de la cocina', 290000, 'mensual'],
            ['empleados', 'Cocinera', 1550000, 'mensual'], ['empleados', 'Auxiliar de cocina y empaque', 1300000, 'mensual'],
            ['otro', 'Empaques y cubiertos desechables', 210000, 'mensual'],
        ],
        'repuestos' => [
            ['arriendo', 'Arriendo del local', 1200000, 'mensual'], ['luz', 'Luz', 140000, 'mensual'],
            ['internet', 'Internet y celular', 70000, 'mensual'], ['empleados', 'Mecánico ayudante', 1400000, 'mensual'],
            ['transporte', 'Envíos de los proveedores', 90000, 'mensual'],
        ],
    ];

    // [texto, tipo, %, lleva, paga, buscar, desde, hasta, estado]: días relativos a hoy.
    public const PROMOCIONES = [
        'tienda' => [
            ['Lleva 3 maltas y paga 2, hasta el domingo.', 'nxm', null, 3, 2, ['malta'], -9, 2, 1],
            ['10% en lácteos la semana de la quincena.', 'porcentaje', 0.10, null, null, ['leche', 'queso', 'mantequilla'], -40, -33, 1],
        ],
        'drogueria' => [
            ['Vitaminas y suplementos con 10% de descuento.', 'porcentaje', 0.10, null, null, ['vitamin', 'complejo', 'suplemento', 'multivit'], -12, 3, 1],
            ['Cuidado del bebé: lleva 3 y paga 2 en pañitos.', 'nxm', null, 3, 2, ['pañitos'], -50, -36, 1],
        ],
        'restaurante' => [
            ['Perro caliente 2x1 en domicilios.', 'nxm', null, 2, 1, ['Perro caliente'], -15, 5, 1],
            ['Domicilio gratis en almuerzos pedidos antes de las 11 a. m.', 'ninguno', null, null, null, [], 1, 9, 0],
        ],
        'repuestos' => [
            ['15% en aceites para moto este mes.', 'porcentaje', 0.15, null, null, ['Aceite'], -10, 4, 1],
        ],
    ];

    public const RESENAS = [
        'tienda' => [
            5 => ['Llegó rapidísimo y completo.', 'Siempre tienen de todo.', 'Entrega rápida', 'Los huevos llegaron enteritos.', 'Buenos precios'],
            4 => ['Buen servicio, solo que la gaseosa no venía fría.', 'Todo bien, se demoró un poquito por la lluvia.'],
            3 => ['Me cambiaron el arroz por otra marca sin avisar.', 'Llegó, pero tardó casi una hora.'],
            2 => ['Faltó una bolsa de leche y tocó reclamar.'],
            1 => ['Llegó todo revuelto y el pan aplastado.'],
        ],
        'drogueria' => [
            5 => ['Me trajeron el acetaminofén en 15 minutos.', 'Muy buena atención.', 'Discretos y puntuales.', 'Entrega rápida'],
            4 => ['Bien, aunque no tenían la presentación que pedí.', 'Todo bien, el domicilio un poco caro.'],
            3 => ['Se demoraron más de lo prometido.', 'No me llegó la factura con el pedido.'],
            2 => ['El protector solar venía con la tapa rota.'],
            1 => ['Me mandaron otra marca del medicamento sin preguntar.'],
        ],
        'restaurante' => [
            5 => ['La bandeja llegó calientica y bien servida.', 'El sancocho sabe a casa.', 'Comida rica y abundante', 'El corrientazo vale cada peso.'],
            4 => ['Muy rico, pero el jugo llegó sin hielo.', 'Buena sazón, se demoró un poco al mediodía.'],
            3 => ['El arroz llegó algo frío.', 'Faltaron los cubiertos.'],
            2 => ['La mojarra llegó blanda.'],
            1 => ['Tardó más de una hora y llegó frío.'],
        ],
        'repuestos' => [
            5 => ['Me trajeron el aceite y la bujía en media hora.', 'Repuestos originales y buen precio.', 'Muy cumplidos'],
            4 => ['Bien, pero tocó esperar la guaya un día.', 'Buena atención por chat.'],
            3 => ['El filtro no era la referencia exacta.'],
            2 => ['Se demoraron bastante.'],
            1 => ['Me cobraron el domicilio dos veces.'],
        ],
    ];

    public const RESENAS_DOMICILIARIO = [
        5 => ['Muy amable', 'Llegó rápido', 'Llamó antes de llegar'], 4 => ['Puntual', 'Bien'],
        3 => ['Se demoró encontrando la dirección'], 2 => ['No traía vuelto'], 1 => ['Fue grosero'],
    ];

    // Quién entra al conjunto sin ser usuario de la plataforma.
    public const SERVICIOS = [
        ['Técnico de gas domiciliario', 'Revisión periódica del gas'], ['Internet y TV por cable', 'Instalación de fibra'],
        ['Energía (lectura de contador)', null], ['Plomería La Costa', 'Arreglo de fuga en el baño'],
        ['Mudanzas Caribe', 'Mudanza, usa el ascensor de carga'], ['Fumigaciones del Norte', 'Fumigación programada'],
        ['Mantenimiento de ascensores', 'Mantenimiento mensual'], ['Aseo por días', 'Empleada de servicio'],
    ];

    public const OTRAS_APPS = ['Rappi', 'DiDi Food', 'Domicilio de restaurante', 'Mensajería', 'iFood'];
}
