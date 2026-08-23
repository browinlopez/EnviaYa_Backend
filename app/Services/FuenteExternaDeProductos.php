<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * De dónde sale un producto que nadie ha cargado todavía.
 *
 * EL PROBLEMA QUE RESUELVE: si el primer tendero escanea trescientos productos
 * y los trescientos son nuevos, no se le ahorró nada — se le empeoró, porque
 * ahora además tiene que teclear el nombre y tomar la foto de cada uno. El
 * escáner solo sirve si del otro lado hay algo.
 *
 * Open Food Facts es una base abierta y gratuita con consulta por código de
 * barras. Tiene productos colombianos —Postobón, Alpina, Nutresa, Colombina—
 * pero LA COBERTURA ES PARCIAL. Sirve para que escanear algo nuevo llegue con
 * el nombre y la imagen ya puestos y el tendero solo confirme; no sirve para
 * depender de ella, y por eso nunca es un error que no encuentre nada.
 *
 * LO QUE DEVUELVE ES UN BORRADOR, NO UN PRODUCTO. No se guarda solo: una
 * fuente ajena puede traer el nombre en otro idioma o de otra presentación, y
 * meter eso a ciegas en el catálogo maestro lo ensucia igual que el texto
 * libre que se acaba de cerrar. Alguien confirma antes.
 *
 * Se cachea por código —incluidos los fallos— porque el mismo producto que no
 * está se va a escanear en veinte tiendas, y no tiene sentido salir a internet
 * veinte veces para recibir el mismo «no existe». Además, si la red se cae o la
 * fuente tarda, esto NO puede tumbar el escáner: se devuelve null y el tendero
 * escribe el nombre a mano, que es lo que hacía de todos modos.
 */
class FuenteExternaDeProductos
{
    private const URL = 'https://world.openfoodfacts.org/api/v2/product/';

    /** Lo bastante para no repetir la consulta; poco para que se corrija solo. */
    private const HORAS_EN_CACHE = 720; // 30 días

    public function buscar(string $barcode): ?array
    {
        $barcode = preg_replace('/\D/', '', $barcode);

        if (strlen($barcode) < 8) {
            return null;
        }

        $clave = "ean:{$barcode}";

        $guardado = Cache::get($clave);

        // El `false` guardado es «ya preguntamos y no está», que es distinto de
        // «no hemos preguntado». Sin distinguirlos se vuelve a salir a internet
        // en cada escaneo de un producto que la fuente no tiene.
        if ($guardado !== null) {
            return $guardado === false ? null : $guardado;
        }

        $ficha = $this->consultar($barcode);

        Cache::put($clave, $ficha ?? false, now()->addHours(self::HORAS_EN_CACHE));

        return $ficha;
    }

    private function consultar(string $barcode): ?array
    {
        try {
            $r = Http::timeout(4)
                ->withHeaders([
                    // La fuente pide identificarse; sin esto limitan por abuso.
                    'User-Agent' => 'VeciPaYa/1.0 (gerencia@vecipaya.com)',
                ])
                ->get(self::URL . $barcode . '.json', [
                    'fields' => 'product_name,product_name_es,brands,image_front_url,quantity',
                ]);

            if (!$r->successful()) {
                return null;
            }

            $cuerpo = $r->json();

            if (($cuerpo['status'] ?? 0) !== 1) {
                return null;
            }

            $p = $cuerpo['product'] ?? [];

            // El nombre en español primero: es el que va a leer el tendero.
            $nombre = trim($p['product_name_es'] ?? '') ?: trim($p['product_name'] ?? '');

            if ($nombre === '') {
                return null;
            }

            // «Coca-Cola 400 ml» se lee mejor que «Coca-Cola» a secas cuando en
            // el estante hay tres tamaños del mismo producto.
            $tamano = trim($p['quantity'] ?? '');

            if ($tamano !== '' && stripos($nombre, $tamano) === false) {
                $nombre .= " {$tamano}";
            }

            return [
                'name'   => mb_substr($nombre, 0, 255),
                'brand'  => mb_substr(trim(explode(',', $p['brands'] ?? '')[0]), 0, 120) ?: null,
                'image'  => $p['image_front_url'] ?? null,
                'fuente' => 'openfoodfacts',
            ];
        } catch (\Throwable $e) {
            /*
             * Que la fuente esté caída no puede impedir cargar un producto: el
             * tendero está de pie en la tienda con el celular en la mano.
             */
            Log::info('Fuente externa de productos no disponible', [
                'barcode' => $barcode,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
