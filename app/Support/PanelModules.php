<?php

namespace App\Support;

/**
 * CATÁLOGO DE MÓDULOS DEL PANEL
 *
 * Fuente única de qué secciones existen. El panel de React tiene su propia
 * copia en `src/lib/modules.js`, y las dos tienen que decir lo mismo: si acá se
 * agrega un módulo y allá no, la sección queda accesible por API pero invisible
 * en el menú; al revés, el menú ofrece una pantalla que el servidor rechaza.
 *
 * La clave es la que se guarda en `area_module` y la que consultan tanto el
 * middleware como el menú, así que renombrarla invalida los permisos ya
 * asignados: para renombrar hay que migrar la tabla, no solo editar esto.
 *
 * `manage` distingue mirar de tocar. Contabilidad necesita ver los pedidos para
 * cuadrar la caja, pero no cambiarles el estado; Comercial administra el
 * catálogo pero no toca los pagos. Sin esa distinción el permiso se vuelve
 * todo-o-nada y termina dándose de más "porque lo necesita para trabajar".
 */
class PanelModules
{
    /** clave => [rótulo, grupo del menú] */
    public const CATALOGO = [
        'panel' => ['Panel', 'General'],

        'ordenes'       => ['Órdenes', 'Operación'],
        'domiciliarios' => ['Domiciliarios', 'Operación'],
        'pagos'         => ['Pagos', 'Operación'],

        'negocios'           => ['Negocios', 'Catálogo'],
        'productos'          => ['Productos', 'Catálogo'],
        'categorias'         => ['Categorías', 'Catálogo'],
        'categorias-negocio' => ['Cat. de negocio', 'Catálogo'],

        'marketing'                => ['Resumen de marketing', 'Marketing'],
        'marketing.anunciantes'    => ['Anunciantes', 'Marketing'],
        'marketing.campanas'       => ['Campañas', 'Marketing'],
        'marketing.banners'        => ['Banners', 'Marketing'],
        'marketing.cupones'        => ['Cupones', 'Marketing'],
        'marketing.destacados'     => ['Destacados', 'Marketing'],
        'marketing.notificaciones' => ['Notificaciones', 'Marketing'],

        'usuarios'     => ['Usuarios', 'Comunidad'],
        'propietarios' => ['Propietarios', 'Comunidad'],
        'conjuntos'    => ['Conjuntos', 'Comunidad'],
        'resenas'      => ['Reseñas', 'Comunidad'],
        'chats'        => ['Conversaciones', 'Comunidad'],

        'reportes'  => ['Reportes', 'Control'],
        'auditoria' => ['Auditoría', 'Control'],
        'areas'     => ['Roles y accesos', 'Control'],
        'ajustes'   => ['Ajustes', 'Control'],
    ];

    /** @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::CATALOGO);
    }

    public static function existe(string $clave): bool
    {
        return isset(self::CATALOGO[$clave]);
    }

    public static function rotulo(string $clave): string
    {
        return self::CATALOGO[$clave][0] ?? $clave;
    }

    /** El catálogo en la forma que consume el panel para dibujar la matriz. */
    public static function paraPanel(): array
    {
        $salida = [];

        foreach (self::CATALOGO as $clave => [$rotulo, $grupo]) {
            $salida[] = ['key' => $clave, 'label' => $rotulo, 'group' => $grupo];
        }

        return $salida;
    }
}
