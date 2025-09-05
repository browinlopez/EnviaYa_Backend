<?php

namespace App\Models\Reviews;

use App\Models\Audit\Audit;
use App\Models\Domiciliary;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DomiciliaryReview extends Audit
{
    protected $table = 'domiciliary_reviews';
    protected $primaryKey = 'reviews_id';
    public $timestamps = false;

    protected $fillable = [
        'domiciliary_id',
        'buyer_id',
        'qualification',
        'comment',
        'state'
    ];

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'domiciliary_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'user_id');
    }
}
