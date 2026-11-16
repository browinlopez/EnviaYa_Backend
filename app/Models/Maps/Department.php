<?php

declare(strict_types=1);

namespace App\Models\Maps;

use App\Models\Audit\Audit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Audit
{
    use HasFactory;

    protected $table = 'departments';

    protected $primaryKey = 'id';

    protected $fillable = ['name', 'country_id'];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    /** @return HasMany<Municipality, $this> */
    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class, 'department_id', 'id');
    }
}
