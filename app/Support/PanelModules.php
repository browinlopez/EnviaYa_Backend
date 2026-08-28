<?php

namespace App\Support;

/**
 * CATÁLOGO DE MÓDULOS DEL PANEL
 *
 * Fuente única de qué secciones existen, y la única: el panel de React NO tiene
 * copia de esta lista. La pide con `paraPanel()` para dibujar la matriz de
 * Áreas, y su menú declara la clave de cada entrada contra ella. Así agregar un
 * módulo acá basta para que exista en los dos lados.
 *
 * (Este comentario decía que la copia vivía en `src/lib/modules.js` y avisaba
 * de mantenerlas iguales. Ese archivo no existe — el panel nunca llegó a
 * tenerlo. Se corrige porque un aviso sobre un riesgo que ya no existe hace
 * perder el tiempo a quien venga a comprobarlo.)
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
        // Comprobantes de las entregas. Van junto a Pagos y no en un grupo
        // propio: quien concilia la caja mira las dos cosas seguidas.
        'facturas'      => ['Comprobantes', 'Operación'],
        'liquidaciones' => ['Liquidaciones', 'Operación'],
        // El efectivo que los domiciliarios tienen encima y sus consignaciones.
        'efectivo'      => ['Efectivo', 'Operación'],

        // Seguridad y salud en el trabajo. Grupo propio porque responde a otra
        // pregunta que el resto del panel: no "cómo va la operación" sino
        // "está la gente en condiciones de operar".
        'sst.documentos' => ['Documentación', 'SST'],
        'sst.incidentes' => ['Incidentes', 'SST'],

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
        'pqrs'         => ['PQRS', 'Comunidad'],
        // Lo que llega por los formularios de la web pública. Va en Comunidad
        // y no en Catálogo porque quien escribe todavía no es un negocio ni un
        // domiciliario: es alguien de fuera pidiendo entrar.
        'solicitudes'  => ['Solicitudes de la web', 'Comunidad'],

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
