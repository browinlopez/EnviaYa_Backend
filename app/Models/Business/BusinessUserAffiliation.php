<?php

namespace App\Models\Business;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;

class BusinessUserAffiliation extends Model
{
    protected $table = 'business_user_affiliations';
    protected $fillable = ['user_id','busines_id'];
    public $timestamps = false;

    public function business()
    {
        return $this->belongsTo(Business::class,'busines_id','busines_id');
    }
}
