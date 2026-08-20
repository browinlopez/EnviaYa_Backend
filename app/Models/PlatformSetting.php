<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * UN AJUSTE DE LA PLATAFORMA
 *
 * Es un modelo para una sola cosa: que los cambios queden AUDITADOS. Se podría
 * escribir la tabla con el constructor de consultas y una línea menos de código,
 * pero entonces cambiar el reparto del domicilio —que mueve plata en cada pedido
 * que venga— no dejaría rastro de quién lo hizo.
 *
 * Con el modelo auditable, cada cambio aparece en el módulo de Auditoría del
 * panel con el valor anterior y el nuevo, junto al resto de los cambios del
 * sistema y sin código aparte para eso.
 *
 * La lectura NO pasa por acá: la hace `Ajustes` con una consulta plana y caché,
 * porque el tope de entregas se consulta al crear cada pedido y un modelo por
 * lectura sería un coste regalado.
 */
class PlatformSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'platform_settings';

    /*
     * La primaria es el `id` numérico y no la clave del ajuste, aunque la clave
     * sea lo que lo identifica. Con `key` como primaria, el paquete de auditoría
     * intentaba guardar "operacion.entregas_simultaneas" en `auditable_id`, que
     * es un entero: MySQL lo rechazaba y guardar un ajuste devolvía un 500.
     * SQLite lo aceptaba sin queja, así que las pruebas pasaban y el fallo solo
     * salía contra la base de verdad.
     */
    protected $fillable = ['key', 'value', 'updated_by'];

    /** Lo que interesa del cambio es el valor; el autor va en la propia auditoría. */
    protected $auditInclude = ['value'];
}
