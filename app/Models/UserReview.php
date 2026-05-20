<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReview extends Audit
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'domiciliary_id',
        'qualification',
        'comment',
        'state'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function domiciliary()
    {
        return $this->belongsTo(Domiciliary::class, 'domiciliary_id', 'id');
    }
}
