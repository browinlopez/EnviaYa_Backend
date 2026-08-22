<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le da el módulo `solicitudes` a las áreas que deben verlo.
 *
 * POR QUÉ HACE FALTA UNA MIGRACIÓN Y NO BASTA CON AreasSemilla
 *
 * El reparto de fábrica solo se aplica entero, y solo cuando alguien corre
 * `db:seed --class=AreaSeeder`, que además PISA los ajustes hechos a mano
 * desde el panel sobre las siete áreas de fábrica. Un módulo nuevo no puede
 * depender de que alguien recuerde ejecutar eso en producción: sin la fila en
 * `area_module`, la sección existe en el código, aparece en el catálogo y no
 * la ve nadie —ni el administrador—, porque el middleware `modulo:` la
 * rechaza.
 *
 * `migrate` es lo único que sí se ejecuta siempre en un despliegue. Es el
 * mismo razonamiento por el que las áreas se crearon en una migración y no
 * solo en un seeder.
 *
 * QUÉ NO HACE
 *
 * No toca ninguna otra fila. Concede el módulo nuevo y se va: si alguien le
 * quitó Reseñas a Comercial desde el panel, sigue sin tenerlas.
 *
 * QUIÉN LO RECIBE
 *
 *   tecnologia .... todo, es el área de sistema
 *   comercial ..... ve y gestiona: un tendero que pide entrar por la web es
 *                   literalmente su trabajo
 *   calidad ....... ve y gestiona: las eliminaciones de cuenta tienen plazo
 *                   legal y son de quien responde por el tratamiento de datos
 *   gerencia ...... solo ver, como con todo lo demás
 */
return new class extends Migration
{
    private const REPARTO = [
        'sistema'    => true,
        'comercial'  => true,
        'calidad'    => true,
        'gerencia'   => false,
    ];

    public function up(): void
    {
        foreach (self::REPARTO as $codigo => $gestiona) {
            $areaId = DB::table('areas')->where('code', $codigo)->value('id');

            // Un área que no existe no es un error: alguien pudo borrarla o
            // renombrarla desde el panel, y no le corresponde a esta migración
            // resucitarla.
            if (!$areaId) {
                continue;
            }

            DB::table('area_module')->updateOrInsert(
                ['area_id' => $areaId, 'module' => 'solicitudes'],
                [
                    'can_view'   => true,
                    'can_manage' => $gestiona,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('area_module')->where('module', 'solicitudes')->delete();
    }
};
