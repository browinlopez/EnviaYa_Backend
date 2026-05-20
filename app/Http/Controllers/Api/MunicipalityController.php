<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\FormatsDates;
use App\Services\MunicipalityService;

class MunicipalityController extends Controller
{
    use FormatsDates;

    protected $municipalityService;

    public function __construct(MunicipalityService $municipalityService)
    {
        $this->municipalityService = $municipalityService;
    }

    public function index()
    {
        $municipalities = $this->municipalityService->listMunicipalities();
        return response()->json([
            'municipalities' => $this->formatDatesRecursively($municipalities->toArray())
        ]);
    }
}
