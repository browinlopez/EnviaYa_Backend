<?php

namespace App\Models;

use App\Models\Audit\Audit;
use App\Models\Reviews\BusinessReview;
use App\Models\Reviews\DomiciliaryReview;
use Illuminate\Database\Eloquent\Model;

class Buyer extends Audit
{
    protected $table = 'buyer';
    protected $primaryKey = 'buyer_id';
    public $timestamps = false;

    protected $fillable = ['user_id', 'qualification', 'state'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function businessReviews()
    {
        return $this->hasMany(BusinessReview::class, 'buyer_id', 'buyer_id');
    }

    public function domiciliaryReviews()
    {
        return $this->hasMany(DomiciliaryReview::class, 'buyer_id', 'buyer_id');
    }
}
