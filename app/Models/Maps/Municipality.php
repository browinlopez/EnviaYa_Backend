<?php

declare(strict_types=1);

namespace App\Models\Maps;

use App\Models\Audit\Audit;
use App\Models\User\UserAddress;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Municipality extends Audit
{
    use HasFactory;

    protected $table = 'municipalities';

    protected $primaryKey = 'id';

    protected $fillable = ['name', 'department_id'];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function userAddresses()
    {
        return $this->hasMany(UserAddress::class, 'municipality_id', 'id');
    }
}
