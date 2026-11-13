<?php

declare(strict_types=1);

namespace App\Models\Business;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUserAffiliation extends Audit
{
    protected $table = 'business_user_affiliations';

    protected $fillable = ['user_id', 'busines_id'];

    public $timestamps = false;

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
