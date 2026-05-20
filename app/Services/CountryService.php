<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Department;

class CountryService
{
    public function listCountries()
    {
        return Country::orderBy('name')->get();
    }

    public function getDepartments($countryId)
    {
        return Department::where('country_id', $countryId)->orderBy('name')->get();
    }
}
