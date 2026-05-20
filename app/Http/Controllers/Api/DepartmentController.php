<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\FormatsDates;
use App\Services\DepartmentService;

class DepartmentController extends Controller
{
    use FormatsDates;

    protected $departmentService;

    public function __construct(DepartmentService $departmentService)
    {
        $this->departmentService = $departmentService;
    }

    public function index()
    {
        $departments = $this->departmentService->listDepartments();
        return response()->json([
            'departments' => $this->formatDatesRecursively($departments->toArray())
        ]);
    }

    public function municipalities($departmentId)
    {
        $municipalities = $this->departmentService->getMunicipalities($departmentId);
        return response()->json([
            'municipalities' => $this->formatDatesRecursively($municipalities->toArray())
        ]);
    }
}
