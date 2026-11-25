<?php

declare(strict_types=1);

namespace App\Models\Negocio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un gasto del negocio: la luz, el arriendo, la empleada… Ver la migración
 * `lo_que_cuesta_y_lo_que_queda`.
 */
class GastoDelNegocio extends Model
{
    public const MENSUAL = 'mensual';

    public const UNA_VEZ = 'una_vez';

    /** Los tipos que ofrece la app, con su nombre por defecto. */
    public const TIPOS = [
        'luz' => 'Luz',
        'agua' => 'Agua',
        'gas' => 'Gas',
        'arriendo' => 'Arriendo',
        'internet' => 'Internet y teléfono',
        'empleados' => 'Empleados',
        'transporte' => 'Transporte',
        'otro' => 'Otro gasto',
    ];

    protected $table = 'gastos_del_negocio';

    protected $primaryKey = 'gasto_id';

    protected $fillable = [
        'busines_id', 'tipo', 'nombre', 'monto', 'frecuencia', 'desde', 'hasta',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'desde' => 'date',
        'hasta' => 'date',
    ];

    /** @return HasMany<GastoDelMes, $this> */
    public function meses(): HasMany
    {
        return $this->hasMany(GastoDelMes::class, 'gasto_id', 'gasto_id');
    }
}
