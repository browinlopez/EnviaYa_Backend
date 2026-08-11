<?php

namespace App\Models\Maps;

use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Audit\Audit;

class Alias extends Audit
{
    use HasFactory;

    protected $table = 'alias';
    protected $primaryKey = 'alias_id';

    protected $fillable = [
        'name'
    ];

    // RelaciÃ³n con las direcciones de usuarios
    public function userAddresses()
    {
        return $this->hasMany(UserAddress::class, 'alias_id', 'alias_id');
    }
}
