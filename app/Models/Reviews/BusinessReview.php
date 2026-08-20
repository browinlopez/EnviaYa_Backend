<?php

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\Buyer\Buyer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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
        'state'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function buyer()
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
