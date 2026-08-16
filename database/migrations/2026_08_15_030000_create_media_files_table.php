<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos de cualquier entidad, en su propia tabla.
 *
 * Antes la imagen vivía en una columna del registro (`business.logo`,
 * `products.image`), lo que traía dos problemas:
 *
 *  1. Un negocio tiene muchos archivos —logo, galería, documentos— y una
 *     columna solo guarda uno. Es una relación uno a muchos.
 *  2. Se estaba guardando la URL firmada de R2, que mide ~600 caracteres y no
 *     cabía en varchar(255); y además caduca, así que persistirla es un error
 *     de raíz.
 *
 * Acá se guarda la CLAVE del objeto (ruta dentro del bucket), que es
 * permanente. La URL se resuelve al leer: pública si hay dominio configurado,
 * firmada y temporal si no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();

            // Relación genérica: el mismo mecanismo sirve para negocios,
            // productos, usuarios y conjuntos sin una tabla por tipo.
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');

            $table->string('tipo', 20)->default('galeria'); // logo | galeria | documentos
            $table->string('object_key', 512);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Marca la imagen principal (la que la app muestra como logo).
            $table->boolean('is_primary')->default(false);

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'media_entidad_idx');
            $table->index(['entity_type', 'entity_id', 'is_primary'], 'media_principal_idx');
        });

        /*
         * Rescate del archivo ya subido a R2 que no llegó a registrarse: la
         * escritura en el bucket sí funcionó, pero el UPDATE de `business.logo`
         * falló por longitud y se perdió la referencia.
         */
        $huerfano = 'negocios/1-tienda-el-progreso/logo/20260815-144306-sx147t-logo-u.jpeg';

        if (DB::table('business')->where('busines_id', 1)->exists()) {
            DB::table('media_files')->insert([
                'entity_type'   => 'negocios',
                'entity_id'     => 1,
                'tipo'          => 'logo',
                'object_key'    => $huerfano,
                'original_name' => 'logo.jpeg',
                'mime_type'     => 'image/jpeg',
                'is_primary'    => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
