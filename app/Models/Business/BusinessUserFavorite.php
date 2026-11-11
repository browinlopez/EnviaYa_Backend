<?php

declare(strict_types=1);

namespace App\Models\Business;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\User;

class BusinessUserFavorite extends Audit
{
    protected $table = 'business_user_favorites';

    protected $fillable = ['user_id', 'busines_id'];

    public function business()
    {
        return $this->belongsTo(Business::class, 'busines_id', 'busines_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
