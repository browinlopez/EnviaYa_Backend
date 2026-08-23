<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;

/**
 * Una carga de catálogo por Excel y cómo le fue.
 *
 * No extiende `Audit` a propósito: la auditoría registra quién cambió qué en
 * los datos del negocio, y esto ya ES el registro de una operación. Auditarlo
 * sería guardar dos veces lo mismo.
 */
class CatalogUpload extends Model
{
    protected $table = 'catalog_uploads';
    protected $primaryKey = 'catalog_upload_id';

    protected $fillable = [
        'busines_id',
        'user_id',
        'archivo',
        'nombre_original',
        'estado',
        'filas',
        'creados',
        'actualizados',
        'rechazados',
        'resumen',
    ];

    protected $casts = [
        'resumen' => 'array',
    ];
}
