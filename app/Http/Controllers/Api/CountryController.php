<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\FormatsDates;
use App\Services\CountryService;

class CountryController extends Controller
{
    use FormatsDates;

    protected $countryService;

    public function __construct(CountryService $countryService)
    {
        $this->countryService = $countryService;
    }

    public function index()
    {
        $countries = $this->countryService->listCountries();
        return response()->json([
            'countries' => $this->formatDatesRecursively($countries->toArray())
        ]);
    }

    public function departments($countryId)
    {
        $departments = $this->countryService->getDepartments($countryId);
        return response()->json([
            'departments' => $this->formatDatesRecursively($departments->toArray())
        ]);
    }
}
