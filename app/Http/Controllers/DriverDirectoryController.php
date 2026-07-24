<?php

namespace App\Http\Controllers;

use App\Http\Requests\DriverDirectoryRequest;
use App\Services\DriverDirectoryService;
use Illuminate\Http\JsonResponse;

/** Lists the drivers matching the directory criteria (feature 027). */
class DriverDirectoryController extends Controller
{
    /** GET /api/drivers — the name-sorted drivers matching the name / modes / warehouse criteria. */
    public function index(DriverDirectoryRequest $request, DriverDirectoryService $directory): JsonResponse
    {
        $rows = $directory->search(
            $request->validated('name'),
            $request->modes(),
            $request->validated('warehouse'),
        );

        return response()->json(['data' => $rows]);
    }
}
