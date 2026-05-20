<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Audit
{
    use HasFactory;

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
        return $this->hasMany(Product::class, 'category_id', 'id');
    }
}
