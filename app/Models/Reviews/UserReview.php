<?php

declare(strict_types=1);

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReview extends Audit
{
    protected $table = 'user_reviews';

    protected $primaryKey = 'reviews_id';

    // Ver nota en BusinessReview: la tabla ya tenía las columnas de fecha.
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'domiciliary_id',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<Domiciliary, $this> */
    public function domiciliary(): BelongsTo
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }
}
