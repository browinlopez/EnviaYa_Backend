<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\CatalogoDeAjustes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LEE Y ESCRIBE LOS AJUSTES DE LA PLATAFORMA
 *
 * `Ajustes::valor('operacion.entregas_simultaneas')` en vez de
 * `config('services.max_active_deliveries')`. La diferencia es que ahora se puede
 * cambiar desde el panel, queda quién lo hizo y no hace falta reiniciar nada.
 *
 * VA EN CACHÉ porque se lee en el camino caliente: el tope de entregas
 * simultáneas se consulta al crear cada pedido y al asignar cada domiciliario.
 * Una consulta a la base por cada una de esas comprobaciones sería un coste
 * regalado para un puñado de filas que casi nunca cambian.
 *
 * La caché se guarda como UN mapa y no como una entrada por clave: así se
 * invalida entera de una, y no puede quedar la mitad vieja y la mitad nueva —que
 * con el reparto del domicilio significaría liquidar dos pedidos de la misma
 * tarde con porcentajes distintos.
 */
class Ajustes
{
    private const CLAVE_CACHE = 'ajustes.plataforma';

    /** @var array<string, string|null>|null */
    private static ?array $memoria = null;

    /**
     * El valor de un ajuste, ya convertido a su tipo.
     *
     * Sin fila en la tabla devuelve el valor por defecto del catálogo, que sale
     * de la configuración: una instalación nueva funciona sin sembrar nada.
     */
    public static function valor(string $clave): mixed
    {
        $crudos = self::crudos();

        return CatalogoDeAjustes::convertir($clave, $crudos[$clave] ?? null);
    }

    /**
     * Todos los ajustes del catálogo con su valor actual.
     *
     * @return array<string, mixed>
     */
    public static function todos(): array
    {
        $crudos = self::crudos();
        $salida = [];

        foreach (CatalogoDeAjustes::todos() as $clave => $def) {
            $salida[$clave] = CatalogoDeAjustes::convertir($clave, $crudos[$clave] ?? null);
        }

        return $salida;
    }

    /** Qué claves están guardadas en la tabla, o sea cuáles se cambiaron a mano. */
    public static function personalizadas(): array
    {
        return array_keys(self::crudos());
    }

    /**
     * Guarda los que cambiaron y devuelve qué cambió, con su valor anterior.
     *
     * Solo escribe lo que de verdad es distinto. Guardar los seis ajustes cada
     * vez que alguien toca uno llenaría la auditoría de cambios que no ocurrieron,
     * y entonces el registro deja de servir para saber quién cambió qué.
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, array{antes: mixed, ahora: mixed}>
     */
    public static function guardar(array $valores, ?int $usuarioId): array
    {
        $antes  = self::todos();
        $cambios = [];

        foreach ($valores as $clave => $valor) {
            if (!CatalogoDeAjustes::existe($clave)) {
                continue;
            }

            $texto = CatalogoDeAjustes::aTexto($clave, $valor);
            $nuevo = CatalogoDeAjustes::convertir($clave, $texto);

            if ($nuevo === $antes[$clave]) {
                continue;
            }

            /*
             * Por el MODELO y no con el constructor de consultas, aunque sea una
             * línea más: así el cambio queda en la auditoría con su valor
             * anterior. Cambiar el reparto del domicilio mueve plata en cada
             * pedido que venga; sin rastro de quién lo hizo, seis meses después
             * nadie puede explicar por qué marzo no cuadra con abril.
             */
            PlatformSetting::updateOrCreate(
                ['key' => $clave],
                ['value' => $texto, 'updated_by' => $usuarioId],
            );

            $cambios[$clave] = ['antes' => $antes[$clave], 'ahora' => $nuevo];
        }

        if ($cambios !== []) {
            self::olvidar();
        }

        return $cambios;
    }

    /** Devuelve un ajuste a su valor por defecto borrando su fila. */
    public static function restablecer(string $clave): void
    {
        PlatformSetting::where('key', $clave)->delete();
        self::olvidar();
    }

    public static function olvidar(): void
    {
        self::$memoria = null;
        Cache::forget(self::CLAVE_CACHE);
    }

    /** @return array<string, string|null> */
    private static function crudos(): array
    {
        /*
         * Dos niveles: memoria del proceso y caché compartida.
         *
         * El de memoria hace falta aparte porque en una misma petición se puede
         * leer el mismo ajuste varias veces (crear el pedido, comprobar el tope,
         * calcular la comisión), y hasta con caché en memoria eso son varias
         * vueltas al almacén.
         */
        if (self::$memoria !== null) {
            return self::$memoria;
        }

        self::$memoria = Cache::rememberForever(self::CLAVE_CACHE, function () {
            /*
             * Si la tabla todavía no existe —una instalación a medio migrar, o
             * las pruebas antes de correr las migraciones— se devuelve vacío y
             * todo funciona con los valores por defecto. Reventar acá dejaría
             * sin arrancar a la aplicación entera por un ajuste.
             */
            try {
                return DB::table('platform_settings')
                    ->pluck('value', 'key')
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });

        return self::$memoria;
    }
}
