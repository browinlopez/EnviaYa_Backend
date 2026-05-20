<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Municipality;

class DepartmentService
{
    public function listDepartments()
    {
        return Department::with('country')->orderBy('name')->get();
    }

    public function getMunicipalities($departmentId)
    {
        return Municipality::where('department_id', $departmentId)->orderBy('name')->get();
    }
}
