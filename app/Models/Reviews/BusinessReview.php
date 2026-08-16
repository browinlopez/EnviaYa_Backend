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
}
