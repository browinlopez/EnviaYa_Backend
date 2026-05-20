<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeOrganization extends Model
{
    protected $table = 'type_organizations';

    protected $fillable = [
        'code',
        'name',
        'bold_name'
    ];

    public function buyers()
    {
        return $this->hasMany(Buyer::class);
    }
}