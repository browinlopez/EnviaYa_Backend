<?php

declare(strict_types=1);

namespace App\Models\Conjunto;

use App\Models\Audit\Audit;

/**
 * Una entrada del libro de la portería: domiciliario o visitante.
 *
 * El registro de entradas se sigue escribiendo con `DB::table` —es una
 * inserción, el hecho en sí, y su autor queda en `registered_by`—. Este
 * modelo existe para lo que SÍ cambia después: marcar la salida. Con
 * `DB::table()->update()` esa escritura no pasaba por los eventos de Eloquent
 * y no quedaba en `audits`; una hora de salida que alguien corrige o adelanta
 * es justo lo que una auditoría tiene que poder contar.
 *
 * Solo se audita lo de la salida: el resto de la fila no se edita.
 */
class ComplexEntry extends Audit
{
    protected $table = 'complex_entries';

    protected $guarded = ['id'];

    protected $auditInclude = ['exited_at', 'exit_registered_by'];

    /*
     * Sin cast de fecha a propósito: las respuestas que ya leían esta columna
     * con `DB::table` la entregan como texto `Y-m-d H:i:s`, y así sigue.
     */
}
