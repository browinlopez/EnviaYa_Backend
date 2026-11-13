<?php

declare(strict_types=1);

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Audit
{
    use HasFactory;

    protected $table = 'category';

    protected $primaryKey = 'category_id';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'state',
        'business_category_id',
    ];

    // Scope para categorías activas
    public function scopeActive($query)
    {
        return $query->where('state', true);
    }

    // Relación con productos
    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }
}
