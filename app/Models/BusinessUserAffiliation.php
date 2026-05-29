<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessUserAffiliation extends Model
{
    protected $table = 'business_user_affiliations';
    protected $fillable = ['user_id', 'business_id'];
    public $timestamps = false;

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id', 'business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
