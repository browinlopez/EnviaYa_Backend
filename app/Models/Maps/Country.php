<?php

namespace App\Models\Maps;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $table = 'countries';
    protected $primaryKey = 'id';
    protected $fillable = ['name', 'iso_code'];

    public function departments()
    {
        return $this->hasMany(Department::class, 'country_id', 'id');
    }
}
