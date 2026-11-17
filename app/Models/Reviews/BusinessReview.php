<?php

declare(strict_types=1);

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessReview extends Audit
{
    protected $table = 'business_reviews';

    protected $primaryKey = 'reviews_id';

    // La tabla tiene created_at/updated_at pero estaban desactivados, así que
    // ninguna reseña guardaba cuándo se hizo y la app no podía ordenarlas ni
    // mostrar la fecha.
    public $timestamps = true;

    protected $fillable = [
        'busines_id',
        'buyer_id',
        'qualification',
        'comment',
        'state',
    ];

    /*
     * `qualification` es `decimal(3,2)`, y PDO de MySQL devuelve DECIMAL como
     * CADENA mientras SQLite lo devuelve numerico. Sin molde, el tipo de este
     * campo en la respuesta dependia del motor: la suite veia 5 y la app
     * instalada recibia "5.00".
     *
     * Lo encontro la prueba de forma de `POST /v1/reviews/store`, que es la
     * que la app llama desde el modal de «califica tu pedido»: al congelarla
     * salieron cuatro `qualification` como `int`.
     */
    protected $casts = [
        'qualification' => 'decimal:2',
    ];

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    /**
     * Vuelve a promediar las reseñas activas del negocio y lo guarda en
     * `business.qualification`, que es la estrella que pinta la app.
     *
     * Vive acá y no en el controlador porque hay CUATRO sitios que borran o
     * modifican reseñas —crear, editar y borrar desde la app, y moderar desde
     * el panel— y hasta ahora cada uno se acordaba por su cuenta. El panel
     * directamente no se acordaba: borraba con un DELETE crudo y la estrella
     * se quedaba con el promedio viejo para siempre, así que moderar una
     * reseña difamatoria la quitaba de la lista sin arreglar la nota.
     *
     * Sin reseñas activas el promedio queda en 0: es lo que ya hacía el
     * camino de creación —`avg()` devuelve null y `round(null)` es 0— y
     * cambiarlo ahora dejaría negocios con la nota de reseñas borradas.
     */
    public static function recalcularPromedio(int $businesId): float
    {
        $promedio = static::where('busines_id', $businesId)
            ->where('state', true)
            ->avg('qualification');

        // Tope de 5.00: la columna es decimal(3,2) y un promedio por encima
        // reventaría el INSERT.
        $promedio = min(round((float) $promedio, 2), 5.00);

        Business::where('busines_id', $businesId)
            ->update(['qualification' => $promedio]);

        return $promedio;
    }
}
