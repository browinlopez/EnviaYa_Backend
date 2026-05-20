<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryBusiness extends Model
{
    use HasFactory;
    
    protected $table = 'categories_business';

    protected $fillable = [
        'name',
        'description',
        "image"
    ];

    public function businesses()
    {
        return $this->hasMany(Business::class, 'category_business_id', 'id');
    }
}
