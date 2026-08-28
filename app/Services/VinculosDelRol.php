<?php

namespace App\Services;

use App\Models\Conjunto\ComplexStaff;
use Illuminate\Support\Facades\DB;

/**
 * EL ROL NO ES UN CAMPO SUELTO: ARRASTRA FILAS.
 *
 * `user.rol` decide por cuál de las tres puertas entra alguien, pero lo que le
 * deja hacer del otro lado vive en OTRAS tablas:
 *
 *     rol 1 comprador     →  buyer
 *     rol 2 tendero       →  owner  →  owner_busines  →  business
 *     rol 3 domiciliario  →  domiciliary
 *     rol 4 personal      →  user.area_id  (no hay tabla propia)
 *     rol 5 y 6 conjunto  →  complex_staff
 *
 * Cambiar el número sin tocar esas filas deja cuentas a medias en las dos
 * direcciones, y ninguna de las dos avisa:
 *
 *  · HACIA ARRIBA — un comprador convertido en dueño de conjunto se queda sin
 *    fila en `complex_staff`. La cuenta inicia sesión y no ve nada, porque todo
 *    el panel de aliados se acota por esa fila. Es el fallo que ya se corrigió
 *    al CREAR usuarios y que seguía abierto al EDITARLOS.
 *
 *  · HACIA ABAJO — a un tendero degradado a comprador le queda su cadena de
 *    propiedad intacta, y `EnsureBusinessOwner` resuelve el negocio por esa
 *    cadena sin mirar el rol: se le quita el rol y no se le quita el acceso.
 *    Comprobado: `GET /v1/negocio/me` seguía devolviendo 200.
 *
 * Acá vive esa correspondencia, en un solo sitio, para que las dos puertas del
 * panel —crear y editar— no puedan volver a comportarse distinto.
 */
class VinculosDelRol
{
    /** Rol de la app => tabla del perfil y los valores con los que nace. */
    private const PERFIL = [
        1 => ['tabla' => 'buyer',       'columnas' => ['qualification' => 0, 'belongs_to_complex' => 0, 'state' => 1]],
        2 => ['tabla' => 'owner',       'columnas' => ['state' => 1]],
        // Entra fuera de turno: lo activa él desde la app o un administrador.
        3 => ['tabla' => 'domiciliary', 'columnas' => ['available' => 0, 'qualification' => 0, 'state' => 1]],
    ];

    /** Dónde guarda el documento cada perfil. La columna no se llama igual. */
    private const DOCUMENTO = [2 => 'document_number', 3 => 'document'];

    public const ROLES_DE_CONJUNTO = [ComplexStaff::ROL_DUENO, ComplexStaff::ROL_CELADOR];

    public const ROL_PERSONAL = 4;

    /**
     * Crea el perfil que le falta a esta persona para su rol.
     *
     * Idempotente: si ya lo tiene no hace nada. Hace falta así porque al editar
     * no se sabe de dónde viene la cuenta —puede haber sido tendero antes— y
     * duplicar la fila rompería las consultas que esperan una sola.
     */
    public function crearPerfilSiFalta(int $userId, int $rol, ?string $documento = null): void
    {
        $perfil = self::PERFIL[$rol] ?? null;

        if (!$perfil) {
            return;
        }

        if (DB::table($perfil['tabla'])->where('user_id', $userId)->exists()) {
            return;
        }

        $fila = ['user_id' => $userId] + $perfil['columnas'];

        if ($documento !== null && isset(self::DOCUMENTO[$rol])) {
            $fila[self::DOCUMENTO[$rol]] = $documento;
        }

        DB::table($perfil['tabla'])->insert($fila);
    }

    /**
     * Ata a esta persona a un conjunto, o la mueve al que se le indique.
     *
     * `user_id` es único en `complex_staff`: alguien pertenece a un conjunto y
     * solo a uno. Por eso se actualiza en vez de insertar — insertar reventaría
     * con un error de base que el panel enseñaría tal cual.
     */
    public function ponerEnConjunto(int $userId, int $complexId, int $rol, ?int $porQuien = null): void
    {
        ComplexStaff::updateOrCreate(
            ['user_id' => $userId],
            [
                'complex_id' => $complexId,
                'role'       => $rol === ComplexStaff::ROL_DUENO
                    ? ComplexStaff::DUENO
                    : ComplexStaff::CELADOR,
                'state'      => true,
                'created_by' => $porQuien,
            ],
        );
    }

    /**
     * Por qué este cambio de rol NO se puede hacer todavía, o null si se puede.
     *
     * Se rechaza en vez de arrastrar en silencio, y el mensaje dice dónde está
     * la pantalla que lo resuelve. Es el mismo criterio con el que ya se
     * rechaza borrar un área con gente dentro o un anunciante con campañas: lo
     * que tiene historial no se desengancha de paso.
     */
    public function impedimento(int $userId, int $rolNuevo): ?string
    {
        $negocios = app(NegocioDelUsuario::class)->negociosDe($userId);

        if ($rolNuevo !== 2 && $negocios->isNotEmpty()) {
            $nombres = $negocios->pluck('name')->implode(', ');

            return "Esta persona administra {$negocios->count()} negocio(s) ({$nombres}). "
                . 'Cambiarle el rol la dejaría con acceso a ellos pero sin poder entrar por su puerta. '
                . 'Desvincúlala primero en Catálogo → Propietarios.';
        }

        $ficha = ComplexStaff::where('user_id', $userId)->where('state', true)->first();

        if ($ficha && !in_array($rolNuevo, self::ROLES_DE_CONJUNTO, true) && $ficha->esDueno()) {
            $conjunto = DB::table('residential_complexes')
                ->where('complex_id', $ficha->complex_id)
                ->value('name') ?? "#{$ficha->complex_id}";

            return "Esta persona es la administradora de {$conjunto}. Cambiarle el rol dejaría "
                . 'el conjunto sin nadie que lo gestione ni registre entradas en la portería. '
                . 'Nómbrale un reemplazo primero, en Comunidad → Conjuntos.';
        }

        return null;
    }

    /**
     * Cierra lo que el rol nuevo ya no justifica.
     *
     * Solo llega acá lo que `impedimento()` dejó pasar: un celador que deja de
     * serlo. Su ficha se DESACTIVA, no se borra — tiene entradas de portería
     * registradas a su nombre, y borrarla dejaría constancias sin autor.
     */
    public function cerrarVinculos(int $userId, int $rolNuevo): void
    {
        if (in_array($rolNuevo, self::ROLES_DE_CONJUNTO, true)) {
            return;
        }

        ComplexStaff::where('user_id', $userId)
            ->where('state', true)
            ->update(['state' => false]);
    }

    /**
     * ¿Le falta a esta cuenta el vínculo que su rol necesita para servir?
     *
     * Lo usa el panel para contar cuentas a medias sin tener que repetir la
     * consulta en cada pantalla.
     */
    public function estaAMedias(int $userId, int $rol): bool
    {
        if (in_array($rol, self::ROLES_DE_CONJUNTO, true)) {
            return !ComplexStaff::de($userId);
        }

        if ($rol === self::ROL_PERSONAL) {
            return !DB::table('user')->where('user_id', $userId)->value('area_id');
        }

        $perfil = self::PERFIL[$rol] ?? null;

        return $perfil
            ? !DB::table($perfil['tabla'])->where('user_id', $userId)->exists()
            : false;
    }
}
