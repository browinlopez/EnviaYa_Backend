<?php

declare(strict_types=1);

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Buyer\Buyer;
use App\Models\Domiciliary;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomiciliaryReview extends Audit
{
    protected $table = 'domiciliary_reviews';

    protected $primaryKey = 'reviews_id';

    // Ver nota en BusinessReview: la tabla ya tenía las columnas de fecha.
    public $timestamps = true;

    protected $fillable = [
        'domiciliary_id',
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

    /** @return BelongsTo<Domiciliary, $this> */
    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class, 'buyer_id', 'buyer_id');
    }

    /**
     * Vuelve a promediar y lo guarda en `domiciliary.qualification`.
     *
     * Gemelo de BusinessReview::recalcularPromedio, con una diferencia que se
     * conserva a propósito: acá el suelo es 1.00 y no 0. Es lo que ya hacía el
     * camino de creación, y bajar a alguien a 0 por quedarse sin reseñas
     * activas lo dejaría peor que a quien nunca recibió ninguna.
     */
    public static function recalcularPromedio(int $domiciliaryId): float
    {
        $promedio = static::where('domiciliary_id', $domiciliaryId)
            ->where('state', true)
            ->avg('qualification');

        $promedio = max(1.00, min(round((float) $promedio, 2), 5.00));

        Domiciliary::where('domiciliary_id', $domiciliaryId)
            ->update(['qualification' => $promedio]);

        return $promedio;
    }
}
