<?php

namespace App\Models\Audit;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Audit extends Model implements AuditableContract
{
    use Auditable;
}