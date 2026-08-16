<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\Banner;
use App\Models\Marketing\BannerEvent;
use App\Models\Marketing\Coupon;
use App\Models\Marketing\FeaturedBusiness;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SUPERFICIE PÚBLICA DE PUBLICIDAD
 *
 * Lo que consumen la app móvil y el sitio web. Va sin sesión obligatoria: la
 * publicidad se le muestra también a quien todavía no se ha registrado, que es
 * justo a quien más interesa alcanzar. Cuando hay sesión se aprovecha para
 * segmentar y para atribuir el evento a una persona.
 *
 * Nada de acá expone datos del anunciante ni presupuestos: el cliente recibe
 * solo lo que necesita para pintar la pieza y avisar que la mostró.
 */
class AdsController extends Controller
{
    public function __construct(private readonly MediaService $medios)
    {
    }

    /**
     * Banners vigentes para un sitio y un contexto.
     *
     * Parámetros: placement, platform (app|web), municipality_id, complex_id,
     * business_category_id, limit.
     */
    public function banners(Request $request)
    {
        $datos = $request->validate([
            'placement'            => 'required|in:home_hero,home_strip,listing_inline,splash,web_home',
            'platform'             => 'sometimes|in:app,web',
            'municipality_id'      => 'sometimes|nullable|integer',
            'complex_id'           => 'sometimes|nullable|integer',
            'business_category_id' => 'sometimes|nullable|integer',
            'limit'                => 'sometimes|integer|min:1|max:20',
        ]);

        $plataforma = $datos['platform'] ?? 'app';
        $limite     = (int) ($datos['limit'] ?? 10);

        $candidatos = Banner::entregable()
            ->where('placement', $datos['placement'])
            // `both` cubre ambas: el anunciante subió una sola pieza para todo.
            ->whereIn('platform', [$plataforma, 'both'])
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        /*
         * El rol solo se conoce si viene un token válido. Se pide por el guard
         * `sanctum` explícitamente: estas rutas no llevan middleware de
         * autenticación —la publicidad también se le muestra a quien no ha
         * entrado— y `$request->user()` sin guard consultaría el de sesión web,
         * que en una API siempre devuelve null.
         */
        $contexto = [
            'municipality_id'      => $datos['municipality_id'] ?? null,
            'complex_id'           => $datos['complex_id'] ?? null,
            'business_category_id' => $datos['business_category_id'] ?? null,
            'rol'                  => $request->user('sanctum')?->rol,
        ];

        $elegidos = $candidatos
            ->filter(fn (Banner $b) => $b->aplicaA($contexto))
            ->take($limite)
            ->values();

        $imagenes = $this->imagenesDe($elegidos->pluck('id')->all());

        return response()->json(
            $elegidos->map(fn (Banner $b) => [
                'id'         => $b->id,
                'title'      => $b->title,
                'subtitle'   => $b->subtitle,
                'image_url'  => $imagenes[$b->id] ?? null,
                'link_type'  => $b->link_type,
                'link_value' => $b->link_value,
                'placement'  => $b->placement,
            ])->all()
        );
    }

    /**
     * Registra una impresión o un clic.
     *
     * El contador de `banners` se sube con un UPDATE atómico y no leyendo,
     * sumando y guardando: dos impresiones simultáneas —lo normal en cuanto la
     * app tiene tráfico— se perderían una a la otra con el segundo método.
     */
    public function track(Request $request, $id)
    {
        $datos = $request->validate([
            'type'     => 'required|in:impression,click',
            'platform' => 'sometimes|in:app,web',
        ]);

        $banner = Banner::find($id);

        // Un banner borrado o apagado mientras el cliente lo tenía en pantalla
        // no es un error del cliente: se responde 204 y no se cuenta nada.
        if (!$banner) {
            return response()->noContent();
        }

        BannerEvent::create([
            'banner_id' => $banner->id,
            'type'      => $datos['type'],
            'user_id'   => $request->user('sanctum')?->user_id,
            'platform'  => $datos['platform'] ?? null,
            'ip'        => $request->ip(),
            'day'       => now()->toDateString(),
        ]);

        $columna = $datos['type'] === 'click' ? 'clicks_count' : 'impressions_count';
        DB::table('banners')->where('id', $banner->id)->increment($columna);

        return response()->noContent();
    }

    /**
     * Negocios destacados vigentes para una posición.
     */
    public function featured(Request $request)
    {
        $datos = $request->validate([
            'placement' => 'sometimes|in:home_top,category_top,search_top',
            'limit'     => 'sometimes|integer|min:1|max:50',
        ]);

        $filas = FeaturedBusiness::vigente()
            ->where('placement', $datos['placement'] ?? 'home_top')
            ->join('business as b', 'b.busines_id', '=', 'featured_businesses.business_id')
            // Un negocio apagado no debe aparecer aunque haya pagado: el
            // destaque compra posición, no visibilidad de algo cerrado.
            ->where('b.state', 1)
            ->orderByDesc('featured_businesses.priority')
            ->limit((int) ($datos['limit'] ?? 10))
            ->get([
                'featured_businesses.id as featured_id',
                'featured_businesses.placement',
                'b.busines_id', 'b.name', 'b.logo', 'b.type', 'b.qualification',
            ]);

        return response()->json($filas);
    }

    /**
     * Comprueba un cupón contra un subtotal y devuelve el descuento.
     *
     * NO lo canjea: solo dice cuánto valdría. El canje ocurre al confirmar el
     * pedido, y separarlo evita que mirar el carrito consuma usos del cupón.
     */
    public function validateCoupon(Request $request)
    {
        $datos = $request->validate([
            'code'        => 'required|string|max:40',
            'subtotal'    => 'required|numeric|min:0',
            'business_id' => 'sometimes|nullable|integer',
        ]);

        $cupon = Coupon::vigente()
            ->where('code', strtoupper(trim($datos['code'])))
            ->first();

        if (!$cupon) {
            return response()->json([
                'valid'   => false,
                'message' => 'El cupón no existe o ya no está vigente.',
            ], 200);
        }

        if ($cupon->agotado()) {
            return response()->json([
                'valid'   => false,
                'message' => 'Este cupón ya alcanzó su número máximo de usos.',
            ], 200);
        }

        $subtotal = (float) $datos['subtotal'];

        if ($subtotal < (float) $cupon->min_order) {
            return response()->json([
                'valid'   => false,
                'message' => 'Este cupón aplica desde $' . number_format((float) $cupon->min_order, 0, ',', '.') . '.',
            ], 200);
        }

        if ($cupon->business_id && (int) ($datos['business_id'] ?? 0) !== (int) $cupon->business_id) {
            return response()->json([
                'valid'   => false,
                'message' => 'Este cupón solo aplica en un negocio específico.',
            ], 200);
        }

        // Tope por persona. Sin sesión no se puede comprobar, así que se deja
        // pasar acá y se vuelve a mirar al confirmar el pedido, que es donde sí
        // hay usuario y donde el límite tiene que sostenerse de verdad.
        $usuario = $request->user('sanctum')?->user_id;
        if ($usuario && $cupon->max_uses_per_user) {
            $usados = DB::table('coupon_redemptions')
                ->where('coupon_id', $cupon->id)
                ->where('user_id', $usuario)
                ->count();

            if ($usados >= $cupon->max_uses_per_user) {
                return response()->json([
                    'valid'   => false,
                    'message' => 'Ya usaste este cupón el número máximo de veces.',
                ], 200);
            }
        }

        $descuento = $cupon->descuentoPara($subtotal);

        return response()->json([
            'valid'       => true,
            'coupon_id'   => $cupon->id,
            'code'        => $cupon->code,
            'description' => $cupon->description,
            'discount'    => $descuento,
            'total'       => round($subtotal - $descuento, 2),
        ]);
    }

    private function imagenesDe(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        return DB::table('media_files')
            ->where('entity_type', Banner::ENTIDAD_MEDIOS)
            ->whereIn('entity_id', $ids)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get(['entity_id', 'object_key'])
            ->groupBy('entity_id')
            ->map(fn ($grupo) => $this->medios->url($grupo->first()->object_key))
            ->all();
    }
}
