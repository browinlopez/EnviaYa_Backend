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
