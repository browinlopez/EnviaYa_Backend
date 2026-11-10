<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Audit extends Model implements AuditableContract
{
    use Auditable;
}
