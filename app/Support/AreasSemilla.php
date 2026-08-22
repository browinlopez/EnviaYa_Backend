<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * LAS ÁREAS DE LA EMPRESA Y QUÉ TOCA CADA UNA
 *
 * El reparto vive acá y no en un seeder suelto porque lo usan dos sitios: la
 * migración que instala el módulo (para que `migrate` por sí solo deje el panel
 * utilizable, sin depender de que alguien acuerde correr las semillas) y el
 * seeder, que sirve para volver a sincronizar cuando se agrega un módulo nuevo
 * al catálogo.
 *
 * CRITERIO DEL REPARTO
 * Se concede lo que el área necesita para su trabajo, no lo que "podría llegar
 * a mirar". `ver` es leer; `gestionar` es crear, editar y borrar, e implica ver.
 * Cuando una tarea cae entre dos áreas se le da a quien responde por ella: los
 * pagos los gestiona Contabilidad aunque Comercial los consulte, y el catálogo
 * lo gestiona Comercial aunque Marketing lo consulte para armar campañas.
 *
 * Solo Tecnología puede repartir permisos (módulo `areas`). Si esa llave se
 * reparte, deja de ser una llave.
 */
class AreasSemilla
{
    /**
     * @return array<string, array{name: string, description: string, is_system: bool, ver: list<string>, gestionar: list<string>}>
     */
    public static function definiciones(): array
    {
        return [
            'sistema' => [
                'name'        => 'Tecnología',
                'description' => 'Acceso completo. Mantiene la plataforma y reparte los permisos de las demás áreas.',
                'is_system'   => true,
                // El asterisco se expande a todo el catálogo al sembrar: así un
                // módulo nuevo queda cubierto sin tener que acordarse de venir
                // a agregarlo acá.
                'ver'         => ['*'],
                'gestionar'   => ['*'],
            ],

            'gerencia' => [
                'name'        => 'Gerencia',
                'description' => 'Ve todo el negocio para decidir. No opera el día a día: no cambia pedidos, catálogo ni pagos.',
                'is_system'   => false,
                /*
                 * Todo el negocio, pero NO las dos secciones de administración
                 * de la propia herramienta:
                 *
                 *  · `areas` exige `gestionar` hasta para listar, así que darle
                 *    solo lectura pondría en su menú una sección que siempre le
                 *    respondería 403. Un permiso que no se puede ejercer es peor
                 *    que no tenerlo: parece un fallo del panel.
                 *  · `ajustes` es configuración técnica (almacenamiento R2), no
                 *    información de negocio.
                 */
                'ver'         => [
                    'panel', 'ordenes', 'domiciliarios', 'pagos', 'facturas',
                    'liquidaciones', 'sst.documentos', 'sst.incidentes', 'pqrs',
                    'negocios', 'productos', 'categorias', 'categorias-negocio',
                    'marketing', 'marketing.anunciantes', 'marketing.campanas',
                    'marketing.banners', 'marketing.cupones',
                    'marketing.destacados', 'marketing.notificaciones',
                    'usuarios', 'propietarios', 'conjuntos', 'resenas', 'chats',
                    'solicitudes',
                    'reportes', 'auditoria',
                ],
                // Deliberadamente vacío. Gerencia necesita la foto completa,
                // pero que pueda editarlo todo convierte su cuenta en una
                // segunda cuenta de administrador sin que nadie lo decidiera.
                'gestionar'   => [],
            ],

            'contabilidad' => [
                'name'        => 'Contabilidad',
                'description' => 'Cuadra la caja: pagos, liquidaciones a negocios y domiciliarios, y lo comprometido en pauta.',
                'is_system'   => false,
                'ver'         => [
                    'panel', 'ordenes', 'pagos', 'facturas', 'liquidaciones',
                    'negocios', 'propietarios', 'domiciliarios',
                    'reportes', 'auditoria', 'marketing',
                ],
                // Los pedidos los ve para cuadrar, pero cambiarles el estado es
                // una decisión operativa que no le corresponde.
                /*
                 * Los comprobantes se emiten SOLOS al entregar, así que
                 * "gestionar" acá significa una sola cosa: anular el que salió
                 * mal. Se le da a Contabilidad porque es quien detecta el error
                 * al cuadrar, y anular deja constancia en vez de borrar.
                 */
                'gestionar'   => ['pagos', 'facturas', 'liquidaciones'],
            ],

            'marketing' => [
                'name'        => 'Marketing',
                'description' => 'Pauta, cupones, destacados y notificaciones. Consulta el catálogo y la comunidad para segmentar.',
                'is_system'   => false,
                'ver'         => [
                    'panel', 'reportes',
                    'marketing', 'marketing.anunciantes', 'marketing.campanas',
                    'marketing.banners', 'marketing.cupones',
                    'marketing.destacados', 'marketing.notificaciones',
                    // Para armar la segmentación hace falta saber qué negocios,
                    // categorías y conjuntos existen; no hace falta editarlos.
                    'negocios', 'productos', 'categorias', 'categorias-negocio',
                    'conjuntos', 'usuarios', 'resenas',
                ],
                'gestionar'   => [
                    'marketing', 'marketing.anunciantes', 'marketing.campanas',
                    'marketing.banners', 'marketing.cupones',
                    'marketing.destacados', 'marketing.notificaciones',
                ],
            ],

            'comercial' => [
                'name'        => 'Comercial',
                'description' => 'Capta y mantiene negocios, propietarios y catálogo. Vende el posicionamiento destacado.',
                'is_system'   => false,
                'ver'         => [
                    'panel', 'ordenes', 'reportes',
                    'negocios', 'productos', 'categorias', 'categorias-negocio',
                    'propietarios', 'conjuntos', 'resenas',
                    // Las quejas sobre un negocio son información comercial:
                    // se ven, pero atenderlas es de Calidad.
                    'pqrs',
                    // Un tendero que pide entrar por la web es exactamente el
                    // trabajo de Comercial, así que acá se ven y se atienden.
                    'solicitudes',
                    'marketing', 'marketing.destacados',
                ],
                'gestionar'   => [
                    'negocios', 'productos', 'categorias', 'categorias-negocio',
                    'propietarios', 'conjuntos', 'solicitudes',
                    // El destaque lo vende Comercial aunque el módulo viva en
                    // Marketing: quien negocia el precio es quien lo carga.
                    'marketing.destacados',
                ],
            ],

            'sst' => [
                'name'        => 'SST',
                'description' => 'Seguridad y salud en el trabajo: vinculación, documentación y condiciones de los domiciliarios.',
                'is_system'   => false,
                'ver'         => [
                    'panel', 'domiciliarios', 'ordenes', 'reportes',
                    'sst.documentos', 'sst.incidentes',
                ],
                // Gestiona la ficha del domiciliario porque ahí viven el acuerdo
                // de vinculación y su papelería, y ahora también su
                // documentación vigente y los incidentes que lo involucran.
                'gestionar'   => ['domiciliarios', 'sst.documentos', 'sst.incidentes'],
            ],

            'calidad' => [
                'name'        => 'Calidad',
                'description' => 'Vigila la experiencia: reseñas, conversaciones y cómo se están cumpliendo los pedidos.',
                'is_system'   => false,
                'ver'         => [
                    'panel', 'resenas', 'chats', 'pqrs', 'ordenes',
                    // Un reclamo de "me cobraron mal" se resuelve mirando el
                    // comprobante de ese pedido; sin acceso hay que pedírselo a
                    // Contabilidad y el reclamo espera un día más.
                    'facturas',
                    'domiciliarios', 'negocios', 'reportes',
                    /*
                     * Las solicitudes de eliminación de cuenta entran por la
                     * web y tienen plazo legal. No son un asunto comercial:
                     * son de quien responde por el tratamiento de datos.
                     */
                    'solicitudes',
                    // Un reclamo por un accidente durante la entrega necesita
                    // mirar el incidente; registrarlo sigue siendo de SST.
                    'sst.incidentes',
                ],
                // Puede retirar una reseña que incumple las normas y lleva los
                // PQRS de punta a punta; no edita pedidos ni negocios.
                'gestionar'   => ['resenas', 'pqrs', 'solicitudes'],
            ],
        ];
    }

    /**
     * Crea o actualiza las áreas y su matriz de permisos.
     *
     * Es idempotente a propósito: se ejecuta al instalar y cada vez que se
     * agrega un módulo al catálogo. Lo que NO hace es tocar áreas creadas a
     * mano desde el panel ni permisos que alguien haya ajustado en un área que
     * no sea Tecnología, salvo que se pida explícitamente con $forzar.
     */
    public static function sembrar(bool $forzar = false): void
    {
        foreach (self::definiciones() as $codigo => $d) {
            $existente = DB::table('areas')->where('code', $codigo)->first();

            if (!$existente) {
                $id = DB::table('areas')->insertGetId([
                    'code'        => $codigo,
                    'name'        => $d['name'],
                    'description' => $d['description'],
                    'is_system'   => $d['is_system'],
                    'state'       => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            } else {
                $id = $existente->id;

                // Un reparto ya ajustado desde el panel no se pisa sin permiso.
                if (!$forzar && !$d['is_system']) {
                    continue;
                }
            }

            self::aplicarMatriz($id, $d['ver'], $d['gestionar']);
        }
    }

    private static function aplicarMatriz(int $areaId, array $ver, array $gestionar): void
    {
        $todos = PanelModules::claves();

        $ver       = in_array('*', $ver, true) ? $todos : $ver;
        $gestionar = in_array('*', $gestionar, true) ? $todos : $gestionar;

        // Gestionar sin ver no significa nada: se normaliza acá para que la
        // tabla nunca guarde una combinación imposible.
        $ver = array_values(array_unique(array_merge($ver, $gestionar)));

        DB::table('area_module')->where('area_id', $areaId)->delete();

        $filas = [];

        foreach ($ver as $modulo) {
            if (!PanelModules::existe($modulo)) {
                continue; // una clave vieja tras renombrar el catálogo
            }

            $filas[] = [
                'area_id'    => $areaId,
                'module'     => $modulo,
                'can_view'   => true,
                'can_manage' => in_array($modulo, $gestionar, true),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($filas) {
            DB::table('area_module')->insert($filas);
        }
    }
}
