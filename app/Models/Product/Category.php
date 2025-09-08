<?php

namespace App\Models\Product;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Audit
{
    use HasFactory;

    protected $table = 'category';
    protected $primaryKey = 'category_id';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'state'
    ];

    // Scope para categorías activas
    public function scopeActive($query)
    {
        return $query->where('state', true);
    }

     // Relación con productos
    public function products()
    {
        return $this->hasMany(Product::class, 'category_id', 'category_id');
    }
}
