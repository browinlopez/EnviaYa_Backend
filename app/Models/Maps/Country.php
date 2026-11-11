<?php

declare(strict_types=1);

namespace App\Models\Maps;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Country extends Audit
{
    use HasFactory;

    protected $table = 'countries';

    protected $primaryKey = 'id';

    protected $fillable = ['name', 'iso_code'];

    public function departments()
    {
        return $this->hasMany(Department::class, 'country_id', 'id');
    }
}
