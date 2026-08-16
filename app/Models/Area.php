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

    /**
     * Niveles de acceso dentro de un área.
     *
     * `gestor` crea, edita y borra en lo que su área alcanza. `consulta` ve
     * exactamente lo mismo y no modifica nada: es el auxiliar, el asistente o
     * el practicante que necesita la información para trabajar pero no
     * responde por los cambios.
     */
    public const NIVEL_GESTOR   = 'gestor';
    public const NIVEL_CONSULTA = 'consulta';

    public const NIVELES = [self::NIVEL_GESTOR, self::NIVEL_CONSULTA];

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
     *
     * `$nivel` recorta el resultado a la persona concreta: el área define el
     * ALCANCE —qué secciones— y el nivel la PROFUNDIDAD —si además puede
     * tocarlas—. Un auxiliar y su jefe miran lo mismo; solo uno modifica.
     *
     * Se aplica acá y no en cada llamador para que nadie pueda olvidarlo: el
     * middleware, el menú y el endpoint de permisos pasan todos por este punto.
     */
    public function permisos(string $nivel = self::NIVEL_GESTOR): array
    {
        $matriz = $this->is_system
            ? array_fill_keys(PanelModules::claves(), ['view' => true, 'manage' => true])
            : DB::table('area_module')
                ->where('area_id', $this->id)
                ->get()
                ->mapWithKeys(fn ($f) => [
                    $f->module => [
                        'view'   => (bool) $f->can_view,
                        'manage' => (bool) $f->can_manage,
                    ],
                ])
                ->all();

        if ($nivel === self::NIVEL_CONSULTA) {
            // Se conserva `view` y se apaga `manage`: el alcance no cambia, la
            // profundidad sí. Quitarle también la vista lo dejaría sin poder
            // hacer su trabajo, que es justamente consultar.
            $matriz = array_map(
                fn ($p) => ['view' => $p['view'], 'manage' => false],
                $matriz,
            );
        }

        return $matriz;
    }

    public function puedeVer(string $modulo, string $nivel = self::NIVEL_GESTOR): bool
    {
        return (bool) ($this->permisos($nivel)[$modulo]['view'] ?? false);
    }

    public function puedeGestionar(string $modulo, string $nivel = self::NIVEL_GESTOR): bool
    {
        return (bool) ($this->permisos($nivel)[$modulo]['manage'] ?? false);
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
