<?php

declare(strict_types=1);

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\Business\CategoryBusiness;
use Illuminate\Http\Request;

class CategoryBusinessController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(CategoryBusiness::all());
    }
}
