<?php

namespace App\Models\Owner;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class OwnerBusiness extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'owner_busines';
    public $timestamps = false;

    protected $fillable = [
        'owner_id',
        'busines_id',
        'state'
    ];
}
