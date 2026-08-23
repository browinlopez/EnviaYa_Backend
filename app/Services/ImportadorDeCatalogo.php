<?php

namespace App\Services;

use App\Imports\FilasDeCatalogo;
use App\Models\Product\CatalogUpload;
use App\Models\Product\Category;
use App\Models\Product\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * LA CARGA MASIVA, ARREGLADA.
 *
 * Lo que había antes tenía cuatro problemas y cada uno era suficiente para no
 * poder ponerlo en manos de un tendero:
 *
 *  1. `ProductsImport` usa `ToModel` SIN DEDUPLICAR NADA. Subir dos veces el
 *     mismo archivo duplicaba el catálogo entero, y no había nada que lo
 *     impidiera —ni en el código ni en la base, porque `products_business` no
 *     tenía ni un índice.
 *
 *  2. `ProductBusinessImport` pedía el `products_id` NUMÉRICO en la primera
 *     columna. Un tendero no puede saber ese número; nadie fuera del equipo
 *     puede.
 *
 *  3. Usaba `dump()` dentro del bucle, o sea escribía en la salida de la
 *     petición.
 *
 *  4. No había ruta: solo se invocaba desde un seeder. No era una función del
 *     producto, era algo que corría un desarrollador con acceso al servidor.
 *
 * LA IDENTIDAD ES EL CÓDIGO DE BARRAS. Es lo que hace que volver a subir el
 * mismo archivo actualice en vez de duplicar, y lo que permite que la plantilla
 * tenga tres columnas en vez de doce: lo demás sale del catálogo.
 *
 * SIN CÓDIGO SE ACEPTA EL NOMBRE EXACTO, porque hay tiendas cuyo sistema no lo
 * exporta y negarles la carga por eso sería empujarlas de vuelta al papel. Pero
 * solo si ese nombre identifica a UN producto: si hay dos que se llaman igual,
 * se rechaza la fila y se pide el código, que es justamente el caso que el
 * código de barras existe para resolver.
 */
class ImportadorDeCatalogo
{
    /** Cuántos errores se guardan. Más que esto no se lee, se tira el archivo. */
    private const MAX_ERRORES = 200;

    public function __construct(private CatalogoDeProductos $catalogo)
    {
    }

    public function procesar(CatalogUpload $carga): void
    {
        $carga->update(['estado' => 'procesando']);

        try {
            $ruta = Storage::path($carga->archivo);

            $lector = new FilasDeCatalogo();
            Excel::import($lector, $ruta);

            $this->recorrer($carga, $lector->filas);
        } catch (\Throwable $e) {
            $carga->update([
                'estado'  => 'fallida',
                'resumen' => ['error' => 'No se pudo leer el archivo: ' . $e->getMessage()],
            ]);
        }
    }

    private function recorrer(CatalogUpload $carga, $filas): void
    {
        $creados = 0;
        $actualizados = 0;
        $errores = [];

        $categorias = Category::pluck('category_id', 'name')
            ->mapWithKeys(fn ($id, $name) => [$this->normalizar($name) => (int) $id])
            ->all();

        foreach ($filas as $i => $fila) {
            // +2: la fila 1 son los encabezados y las hojas se cuentan desde 1.
            $numero = $i + 2;

            $f = $this->leerFila($fila);

            if ($f === null) {
                continue; // fila vacía, no es un error
            }

            if ($f['precio'] === null) {
                $errores[] = ['fila' => $numero, 'motivo' => 'Falta el precio.'];
                continue;
            }

            try {
                $producto = $this->resolverProducto($f, $categorias, $numero, $errores);
            } catch (\Throwable $e) {
                $errores[] = ['fila' => $numero, 'motivo' => $e->getMessage()];
                continue;
            }

            if (!$producto) {
                continue; // el motivo ya quedó en $errores
            }

            $existia = DB::table('products_business')
                ->where('busines_id', $carga->busines_id)
                ->where('products_id', $producto->products_id)
                ->exists();

            $this->catalogo->agregarATienda(
                (int) $carga->busines_id,
                (int) $producto->products_id,
                $f['precio'],
                $f['cantidad'],
            );

            $existia ? $actualizados++ : $creados++;
        }

        $carga->update([
            'estado'       => 'terminada',
            'filas'        => count($filas),
            'creados'      => $creados,
            'actualizados' => $actualizados,
            'rechazados'   => count($errores),
            'resumen'      => [
                'errores'  => array_slice($errores, 0, self::MAX_ERRORES),
                'truncado' => count($errores) > self::MAX_ERRORES,
            ],
        ]);
    }

    /**
     * Encontrar —o crear— el producto de una fila.
     *
     * El orden importa: primero el código, que es exacto; después el nombre,
     * que es una apuesta. Y solo se crea si la fila trae con qué.
     */
    private function resolverProducto(array $f, array $categorias, int $numero, array &$errores): ?Product
    {
        if ($f['barcode'] !== null) {
            $existente = Product::where('barcode', $f['barcode'])->first();

            if ($existente) {
                return $existente;
            }
        }

        if ($f['nombre'] !== null) {
            $porNombre = Product::where('name', $f['nombre'])->get();

            if ($porNombre->count() === 1) {
                $p = $porNombre->first();

                /*
                 * Si la fila trae código y el producto no lo tenía, se le pone.
                 * Así el catálogo se va sanando solo con cada carga en vez de
                 * quedarse a medias para siempre.
                 */
                if ($f['barcode'] !== null && !$p->barcode) {
                    $p->fill(['barcode' => $f['barcode']])->save();
                }

                return $p;
            }

            if ($porNombre->count() > 1) {
                $errores[] = [
                    'fila'   => $numero,
                    'motivo' => "Hay {$porNombre->count()} productos que se llaman «{$f['nombre']}». Pon el código de barras para saber cuál es.",
                ];

                return null;
            }
        }

        // No está: solo se crea si la fila trae nombre y categoría.
        if ($f['nombre'] === null) {
            $errores[] = [
                'fila'   => $numero,
                'motivo' => 'El código no está en el catálogo y la fila no trae nombre para darlo de alta.',
            ];

            return null;
        }

        $categoriaId = $f['categoria'] !== null
            ? ($categorias[$this->normalizar($f['categoria'])] ?? null)
            : null;

        if ($categoriaId === null) {
            $errores[] = [
                'fila'   => $numero,
                'motivo' => $f['categoria'] === null
                    ? "«{$f['nombre']}» es nuevo y no trae categoría."
                    : "La categoría «{$f['categoria']}» no existe.",
            ];

            return null;
        }

        return $this->catalogo->proponer([
            'name'        => $f['nombre'],
            'barcode'     => $f['barcode'],
            'brand'       => $f['marca'],
            'category_id' => $categoriaId,
        ], 'excel');
    }

    /**
     * Los encabezados de la plantilla, tolerando cómo los escribe la gente.
     *
     * `maatwebsite` ya normaliza a minúsculas con guion bajo, pero «código de
     * barras» y «codigo_barras» siguen siendo llaves distintas, y quien llena
     * el archivo no tiene por qué acertar con una de ellas.
     */
    private function leerFila($fila): ?array
    {
        $v = fn (array $llaves) => $this->primerValor($fila, $llaves);

        $barcode = $v(['codigo_barras', 'codigo_de_barras', 'codigo', 'ean', 'barcode']);
        $nombre  = $v(['nombre', 'nombre_producto', 'producto', 'descripcion_producto']);
        $precio  = $v(['precio', 'precio_venta', 'valor']);
        $cantidad = $v(['cantidad', 'existencias', 'stock', 'amount']);
        $categoria = $v(['categoria', 'category']);
        $marca   = $v(['marca', 'brand']);

        // Una fila sin nada no es un error, es el final del archivo.
        if ($barcode === null && $nombre === null && $precio === null) {
            return null;
        }

        $barcode = $barcode !== null ? preg_replace('/\D/', '', (string) $barcode) : null;

        return [
            'barcode'   => ($barcode === null || $barcode === '') ? null : $barcode,
            'nombre'    => $nombre !== null ? trim((string) $nombre) : null,
            'marca'     => $marca !== null ? trim((string) $marca) : null,
            'categoria' => $categoria !== null ? trim((string) $categoria) : null,
            'precio'    => $this->aNumero($precio),
            'cantidad'  => (int) ($this->aNumero($cantidad) ?? 0),
        ];
    }

    private function primerValor($fila, array $llaves)
    {
        foreach ($llaves as $k) {
            $valor = is_array($fila) ? ($fila[$k] ?? null) : ($fila[$k] ?? null);

            if ($valor !== null && $valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /** «$ 12.500» y «12500,00» son el mismo número escrito por dos personas. */
    private function aNumero($valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $limpio = preg_replace('/[^\d,.-]/', '', (string) $valor);

        // En Colombia el punto separa miles y la coma decimales.
        $limpio = str_replace('.', '', $limpio);
        $limpio = str_replace(',', '.', $limpio);

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    private function normalizar(?string $t): string
    {
        return trim(mb_strtolower((string) $t));
    }
}
