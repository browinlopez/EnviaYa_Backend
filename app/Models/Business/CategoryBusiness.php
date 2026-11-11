<?php

declare(strict_types=1);

namespace App\Models\Business;

use App\Models\Audit\Audit;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CategoryBusiness extends Audit
{
    use HasFactory;

    protected $table = 'category_business';

    protected $fillable = [
        'name',
        'description',
        'image',
    ];

    // RelaciÃ³n inversa con Business
    public function businesses()
    {
        return $this->hasMany(Business::class, 'type', 'id');
    }
}
