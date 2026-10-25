<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LA PQRS DE LA WEB DEJA DE SER "OTRO", Y PUEDE TRAER SU FOTO
 *
 * La landing tiene un formulario para radicar una PQRS, y entraba como `otro`
 * con la clase escrita en el motivo ("PQRS: Reclamo"). Así no tenía plazo ni
 * se podía filtrar, aunque la web promete por escrito responder en máximo
 * quince días hábiles, que es el plazo de la ley.
 *
 * La foto o el comprobante tampoco tenía dónde llegar: el endpoint solo
 * recibía texto, y la web la pedía por WhatsApp citando el radicado. Ahora
 * viaja con la PQRS.
 *
 * EL ARCHIVO NO VA AL BUCKET DE MEDIOS
 *
 * Ese bucket tiene lectura pública (hace falta para que se vean las fotos de
 * las tiendas). Un comprobante de pago o la foto de un pedido son datos de una
 * persona, así que van al disco privado del servidor (storage/app/private,
 * que vive en el volumen `storage_data`) y el panel los descarga con sesión.
 */
return new class extends Migration
{
    private const TIPOS = [
        'comercio',
        'domiciliario',
        'vecino',
        'conjunto',
        'cobertura',
        'eliminacion',
        'otro',
    ];

    public function up(): void
    {
        Schema::table('landing_requests', function (Blueprint $table) {
            $table->enum('type', [...self::TIPOS, 'pqrs'])->default('otro')->change();

            // Ruta dentro del disco privado. Nunca se publica: el panel pide el
            // archivo por su propia ruta, con permiso del módulo.
            $table->string('attachment_path', 255)->nullable()->after('payload');
            // El nombre con que lo subió la persona, para reconocerlo.
            $table->string('attachment_name', 150)->nullable()->after('attachment_path');
            $table->string('attachment_mime', 80)->nullable()->after('attachment_name');
        });
    }

    public function down(): void
    {
        Schema::table('landing_requests', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_mime']);
        });

        // Antes de achicar el enum, lo que entró como PQRS vuelve a `otro`:
        // si no, MySQL rechaza el cambio por las filas con un valor que ya no
        // existe.
        DB::table('landing_requests')->where('type', 'pqrs')->update(['type' => 'otro']);

        Schema::table('landing_requests', function (Blueprint $table) {
            $table->enum('type', self::TIPOS)->default('otro')->change();
        });
    }
};
