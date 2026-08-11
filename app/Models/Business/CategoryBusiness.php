<?php

namespace App\Models\Business;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Audit\Audit;

class CategoryBusiness extends Audit
{
    use HasFactory;

    protected $table = 'category_business';

    protected $fillable = [
        'name',
        'description',
        "image"
    ];

    // RelaciÃ³n inversa con Business
    public function businesses()
    {
        return $this->hasMany(Business::class, 'type', 'id');
    }
}
