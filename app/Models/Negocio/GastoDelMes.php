<?php

declare(strict_types=1);

namespace App\Models\Negocio;

use Illuminate\Database\Eloquent\Model;

/** El valor de un gasto fijo en un mes concreto, cuando no fue el de siempre. */
class GastoDelMes extends Model
{
    protected $table = 'gastos_del_mes';

    protected $fillable = ['gasto_id', 'periodo', 'monto'];

    protected $casts = ['monto' => 'decimal:2'];
}
