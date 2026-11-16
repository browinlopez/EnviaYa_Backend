<?php

declare(strict_types=1);

namespace App\Models\Business;

use App\Models\Audit\Audit;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    /** @return HasMany<Business, $this> */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'type', 'id');
    }
}
