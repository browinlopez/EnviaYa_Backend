<?php

declare(strict_types=1);

namespace App\Http\Requests\Inversionista;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Los filtros de los módulos del inversionista. Todos opcionales: sin
 * ninguno, los últimos 30 días de toda la plataforma.
 *
 * Una fecha que no es fecha es un 422 que dice cuál campo. Una ventana de más
 * de 366 días también: antes se recortaba en silencio, y quien pedía dos años
 * leía uno creyendo que eran dos.
 */
class TableroDelInversionistaRequest extends FormRequest
{
    /** Un año y un día: el tope de la ventana de los reportes. */
    public const DIAS_MAXIMOS = 366;

    /*
     * El permiso lo resuelve el middleware de la ruta (`inversionista`), que
     * es donde vive en este backend.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
            'municipality_id' => 'nullable|integer|min:1',
            // `business.type`, es decir, `category_business.id`.
            'tipo' => 'nullable|integer|min:1',
            // Solo la descarga del informe lo lee.
            'formato' => 'nullable|in:xlsx,pdf',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                if ($v->errors()->hasAny(['desde', 'hasta']) || ! $this->filled('desde') || ! $this->filled('hasta')) {
                    return;
                }

                $dias = abs(Carbon::parse($this->query('desde'))->startOfDay()
                    ->diffInDays(Carbon::parse($this->query('hasta'))->startOfDay())) + 1;

                if ($dias > self::DIAS_MAXIMOS) {
                    $v->errors()->add('hasta', 'El periodo puede tener hasta '.self::DIAS_MAXIMOS.' días.');
                }
            },
        ];
    }
}
