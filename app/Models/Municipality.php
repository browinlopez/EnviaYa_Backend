<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Municipality extends Model
{
    use HasFactory;

    protected $fillable = ['department_id', 'name', 'code'];

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function userAddresses()
    {
        return $this->hasMany(UserAddress::class, 'municipality_id', 'id');
    }

    public function country()
    {
        return $this->department ? $this->department->country : null;
    }
}
