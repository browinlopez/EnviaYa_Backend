<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * LOS PUNTOS DEL MAPA DE COBERTURA DE LA WEB PÚBLICA
 *
 * La landing dibuja un mapa con los comercios y los conjuntos conectados. Las
 * coordenadas existían desde hacía tiempo en `business` y en
 * `residential_complexes`, pero solo las devolvía la API de administración,
 * detrás de rol 4 y módulo. Un sitio público no puede pasar por ahí.
 *
 * POR QUÉ UN ENDPOINT NUEVO Y NO AMPLIAR `top-businesses-free`
 *
 * 1. Peso. Aquel arrastra por cada negocio sus productos, sus reseñas y sus
 *    propietarios. Para pintar un pin eso es un desperdicio enorme, y el mapa
 *    es lo primero que se carga en /cobertura.
 *
 * 2. Alcance. Un endpoint que existe para un mapa devuelve lo que un mapa
 *    necesita, y así se puede razonar sobre qué se está publicando mirando
 *    treinta líneas en vez de doscientas.
 *
 * QUÉ NO DEVUELVE
 *
 * Nada de personas: ni propietarios, ni teléfonos, ni documentos. Tampoco
 * `people_count` de los conjuntos, que es información de negocio. Solo un
 * nombre, una dirección, un punto y a qué municipio pertenece.
 *
 * QUÉ SÍ ESTÁ PUBLICANDO, Y CONVIENE TENERLO PRESENTE
 *
 * La ubicación exacta de cada comercio aliado y de cada conjunto conectado.
 * Es lo que hace útil al mapa, pero es una decisión de producto, no un
 * detalle técnico. Si algún día se prefiere no exponer los conjuntos, está
 * `COBERTURA_INCLUIR_CONJUNTOS=false` y el mapa sigue funcionando con los
 * comercios.
 *
 * Se cachea unos minutos porque es público, cambia poco y no vale la pena
 * consultar dos tablas en cada visita.
 */
class CoberturaController extends Controller
{
    /** Segundos que se conserva la respuesta. Un comercio nuevo aparece en el
     *  mapa en el próximo corte, y nadie está esperando ese pin al segundo. */
    private const CACHE_SEGUNDOS = 300;

    public function __invoke()
    {
        $datos = Cache::remember(
            'cobertura.mapa',
            self::CACHE_SEGUNDOS,
            fn () => $this->recolectar(),
        );

        return response()->json(['data' => $datos]);
    }

    private function recolectar(): array
    {
        $puntos = $this->negocios();

        if (config('services.cobertura.incluir_conjuntos', true)) {
            $puntos = array_merge($puntos, $this->conjuntos());
        }

        return $puntos;
    }

    /**
     * Comercios publicados que tienen dónde ponerse en el mapa.
     *
     * El filtro de coordenadas descarta tres casos distintos que se ven igual
     * de mal: la columna en null (nunca se ubicó), el 0 exacto (se guardó un
     * formulario vacío) y el (0,0), que cae en el Atlántico medio.
     */
    private function negocios(): array
    {
        return DB::table('business as b')
            ->leftJoin('municipalities as m', 'm.id', '=', 'b.municipality_id')
            ->where('b.state', 1)
            ->whereNotNull('b.latitude')
            ->whereNotNull('b.longitude')
            ->where('b.latitude', '<>', 0)
            ->where('b.longitude', '<>', 0)
            ->orderBy('b.name')
            ->get([
                'b.busines_id',
                'b.name',
                'b.address',
                'b.latitude',
                'b.longitude',
                'b.municipality_id',
                'b.type',
                'm.name as municipio',
            ])
            ->map(fn ($fila) => [
                'tipo'            => 'negocio',
                'id'              => (int) $fila->busines_id,
                'nombre'          => $fila->name,
                'direccion'       => $fila->address,
                'latitude'        => (float) $fila->latitude,
                'longitude'       => (float) $fila->longitude,
                'municipality_id' => $fila->municipality_id !== null
                    ? (int) $fila->municipality_id
                    : null,
                'municipio'       => $fila->municipio,
                // `category_business.id`: 1 Tienda, 2 Farmacia,
                // 3 Restaurante, 4 Tienda de repuesto.
                'categoria'       => $fila->type !== null ? (int) $fila->type : null,
            ])
            ->all();
    }

    /** Conjuntos activos con coordenada. Mismo filtro que arriba. */
    private function conjuntos(): array
    {
        return DB::table('residential_complexes as rc')
            ->leftJoin('municipalities as m', 'm.id', '=', 'rc.municipality_id')
            ->where('rc.state', 1)
            ->whereNotNull('rc.latitude')
            ->whereNotNull('rc.longitude')
            ->where('rc.latitude', '<>', 0)
            ->where('rc.longitude', '<>', 0)
            ->orderBy('rc.name')
            ->get([
                'rc.complex_id',
                'rc.name',
                'rc.address',
                'rc.latitude',
                'rc.longitude',
                'rc.municipality_id',
                'm.name as municipio',
            ])
            ->map(fn ($fila) => [
                'tipo'            => 'conjunto',
                'id'              => (int) $fila->complex_id,
                'nombre'          => $fila->name,
                'direccion'       => $fila->address,
                'latitude'        => (float) $fila->latitude,
                'longitude'       => (float) $fila->longitude,
                'municipality_id' => $fila->municipality_id !== null
                    ? (int) $fila->municipality_id
                    : null,
                'municipio'       => $fila->municipio,
            ])
            ->all();
    }
}
