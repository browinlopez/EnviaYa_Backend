<?php

namespace App\Models\Business;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryBusiness extends Model
{
    use HasFactory;

    protected $table = 'category_business';

    protected $fillable = [
        'name',
        'description',
    ];

    // Relación inversa con Business
    public function businesses()
    {
        return $this->hasMany(Business::class, 'type', 'id');
    }
}
