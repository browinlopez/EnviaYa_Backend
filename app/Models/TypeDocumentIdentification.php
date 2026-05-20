<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeDocumentIdentification extends Model
{
    protected $fillable = [
        'name_sp',
        'name_eng',
        'code',
        'bold_name'
    ];
}
