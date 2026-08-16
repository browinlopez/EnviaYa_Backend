<?php

namespace App\Models;

use App\Support\PanelModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Área de la empresa: la unidad de permisos del panel.
 */
class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['code', 'name', 'description', 'state'];

    protected $casts = [
        'is_system' => 'boolean',
        'state'     => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'area_id');
    }

    /**
     * Permisos del área como ['modulo' => ['view' => bool, 'manage' => bool]].
     *
     * El área de sistema se resuelve sin consultar la tabla: tiene todo por
     * definición, y depender de filas para eso significaría que un borrado
     * accidental deja el panel sin quien reparta permisos.
     */
    public function permisos(): array
    {
        if ($this->is_system) {
            return array_fill_keys(
                PanelModules::claves(),
                ['view' => true, 'manage' => true],
            );
        }

        return DB::table('area_module')
            ->where('area_id', $this->id)
            ->get()
            ->mapWithKeys(fn ($f) => [
                $f->module => [
                    'view'   => (bool) $f->can_view,
                    'manage' => (bool) $f->can_manage,
                ],
            ])
            ->all();
    }

    public function puedeVer(string $modulo): bool
    {
        return (bool) ($this->permisos()[$modulo]['view'] ?? false);
    }

    public function puedeGestionar(string $modulo): bool
    {
        return (bool) ($this->permisos()[$modulo]['manage'] ?? false);
    }

    /**
     * Reescribe la matriz del área.
     *
     * @param array<string, array{view?: bool, manage?: bool}> $matriz
     */
    public function guardarPermisos(array $matriz): void
    {
        DB::transaction(function () use ($matriz) {
            DB::table('area_module')->where('area_id', $this->id)->delete();

            $filas = [];

            foreach ($matriz as $modulo => $flags) {
                if (!PanelModules::existe($modulo)) {
                    continue;
                }

                $gestionar = (bool) ($flags['manage'] ?? false);
                // Gestionar implica ver: guardar lo contrario dejaría un
                // permiso que el middleware nunca podría satisfacer.
                $ver = $gestionar || (bool) ($flags['view'] ?? false);

                if (!$ver) {
                    continue; // la ausencia de fila ES la negación
                }

                $filas[] = [
                    'area_id'    => $this->id,
                    'module'     => $modulo,
                    'can_view'   => true,
                    'can_manage' => $gestionar,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($filas) {
                DB::table('area_module')->insert($filas);
            }
        });
    }
}
