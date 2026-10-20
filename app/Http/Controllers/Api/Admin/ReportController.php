<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardReportRequest;
use App\Services\Admin\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    use ApiResponse;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Báo cáo tổng hợp Dashboard (RPT-01 -> RPT-04).
     *
     * @param DashboardReportRequest $request
     * @return JsonResponse
     */
    public function dashboard(DashboardReportRequest $request): JsonResponse
    {
        $fromDate = $request->input('fromDate', now()->startOfMonth()->toDateString());
        $toDate = $request->input('toDate', now()->toDateString());
        $limit = (int) $request->input('limit', 5);

        $data = $this->reportService->getDashboardReport($fromDate, $toDate, $limit);

        return $this->successResponse(
            $data,
            'Dashboard report generated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Báo cáo Doanh thu (RPT-01).
     *
     * @param DashboardReportRequest $request
     * @return JsonResponse
     */
    public function revenue(DashboardReportRequest $request): JsonResponse
    {
        $fromDate = $request->input('fromDate', now()->startOfMonth()->toDateString());
        $toDate = $request->input('toDate', now()->toDateString());

        $data = $this->reportService->getRevenueReport($fromDate, $toDate);

        return $this->successResponse(
            $data,
            'Revenue report generated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Báo cáo Đơn hàng (RPT-02).
     *
     * @param DashboardReportRequest $request
     * @return JsonResponse
     */
    public function orders(DashboardReportRequest $request): JsonResponse
    {
        $fromDate = $request->input('fromDate', now()->startOfMonth()->toDateString());
        $toDate = $request->input('toDate', now()->toDateString());

        $data = $this->reportService->getOrdersReport($fromDate, $toDate);

        return $this->successResponse(
            $data,
            'Orders report generated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Báo cáo Món bán chạy (RPT-03).
     *
     * @param DashboardReportRequest $request
     * @return JsonResponse
     */
    public function topDishes(DashboardReportRequest $request): JsonResponse
    {
        $fromDate = $request->input('fromDate', now()->startOfMonth()->toDateString());
        $toDate = $request->input('toDate', now()->toDateString());
        $limit = (int) $request->input('limit', 5);

        $data = $this->reportService->getTopSellingDishes($fromDate, $toDate, $limit);

        return $this->successResponse(
            $data,
            'Top selling dishes report generated successfully',
            Response::HTTP_OK
        );
    }

    /**
     * Báo cáo Tình hình Đặt bàn (RPT-04).
     *
     * @param DashboardReportRequest $request
     * @return JsonResponse
     */
    public function reservations(DashboardReportRequest $request): JsonResponse
    {
        $fromDate = $request->input('fromDate', now()->startOfMonth()->toDateString());
        $toDate = $request->input('toDate', now()->toDateString());

        $data = $this->reportService->getReservationsReport($fromDate, $toDate);

        return $this->successResponse(
            $data,
            'Reservations report generated successfully',
            Response::HTTP_OK
        );
    }
}
