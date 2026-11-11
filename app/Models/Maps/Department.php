<?php

declare(strict_types=1);

namespace App\Models\Maps;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Department extends Audit
{
    use HasFactory;

    protected $table = 'departments';

    protected $primaryKey = 'id';

    protected $fillable = ['name', 'country_id'];

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    public function municipalities()
    {
        return $this->hasMany(Municipality::class, 'department_id', 'id');
    }
}
