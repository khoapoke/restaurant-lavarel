<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardReportRequest;
use App\Services\Admin\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReportService $reportService) {}

    public function dashboard(DashboardReportRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->reportService->dashboard($request->validated()),
            'Dashboard report generated successfully'
        );
    }
}
