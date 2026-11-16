<?php

declare(strict_types=1);

namespace App\Models\Maps;

use App\Models\Audit\Audit;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alias extends Audit
{
    use HasFactory;

    protected $table = 'alias';

    protected $primaryKey = 'alias_id';

    protected $fillable = [
        'name',
    ];

    // RelaciÃ³n con las direcciones de usuarios
    /** @return HasMany<UserAddress, $this> */
    public function userAddresses(): HasMany
    {
        return $this->hasMany(UserAddress::class, 'alias_id', 'alias_id');
    }
}
