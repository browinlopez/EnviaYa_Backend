<?php

namespace App\Services;

use App\Models\TypeOrganization;
use Illuminate\Database\Eloquent\Collection;

class TypeOrganizationService
{
    /**
     * Obtiene todos los tipos de organización.
     */
    public function getAll(): Collection
    {
        return TypeOrganization::all();
    }
}
