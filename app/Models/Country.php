<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'code'];

    public function departments()
    {
        return $this->hasMany(Department::class, 'country_id', 'id');
    }
}
