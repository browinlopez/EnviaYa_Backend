<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TypeOrganizationService;
use Illuminate\Http\JsonResponse;

class TypeOrganizationController extends Controller
{
    protected TypeOrganizationService $typeOrganizationService;

    public function __construct(TypeOrganizationService $typeOrganizationService)
    {
        $this->typeOrganizationService = $typeOrganizationService;
    }

    /**
     * Lista todos los tipos de organización.
     */
    public function index(): JsonResponse
    {
        $types = $this->typeOrganizationService->getAll();
        return response()->json([
            'success' => true,
            'data' => $types
        ]);
    }
}
