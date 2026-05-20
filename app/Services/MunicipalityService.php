<?php

namespace App\Services;

use App\Models\Municipality;

class MunicipalityService
{
    public function listMunicipalities()
    {
        return Municipality::with('department.country')->orderBy('name')->get();
    }
}
