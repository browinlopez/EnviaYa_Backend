<?php

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BusinessReview extends Audit
{
    protected $table = 'business_reviews';
    protected $primaryKey = 'reviews_id';
    public $timestamps = false;

    protected $fillable = [
        'busines_id',
        'buyer_id',
        'user_id',
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
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
