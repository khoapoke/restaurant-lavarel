<?php

namespace App\Modules\Report\Services\Admin;

use App\Modules\Core\Enums\OrderStatus;
use App\Modules\Core\Enums\PaymentStatus;
use App\Modules\Core\Enums\ReservationStatus;
use App\Modules\Reservation\Models\DiningTable;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Báo cáo Doanh thu: tổng doanh thu đã thanh toán và chuỗi dữ liệu theo ngày (RPT-01).
     *
     * @param string $fromDate
     * @param string $toDate
     * @return array<string, mixed>
     */
    public function getRevenueReport(string $fromDate, string $toDate): array
    {
        $paidPayments = DB::table('THANH_TOAN as tt')
            ->join('DON_HANG as dh', 'tt.ma_don_hang', '=', 'dh.ma_don_hang')
            ->where('tt.trang_thai', PaymentStatus::PAID->value)
            ->whereDate('dh.ngay_dat_hang', '>=', $fromDate)
            ->whereDate('dh.ngay_dat_hang', '<=', $toDate);

        $totalPaidRevenue = (float) $paidPayments->sum('tt.so_tien');

        $dailySeries = DB::table('THANH_TOAN as tt')
            ->join('DON_HANG as dh', 'tt.ma_don_hang', '=', 'dh.ma_don_hang')
            ->where('tt.trang_thai', PaymentStatus::PAID->value)
            ->whereDate('dh.ngay_dat_hang', '>=', $fromDate)
            ->whereDate('dh.ngay_dat_hang', '<=', $toDate)
            ->select(
                DB::raw('DATE(dh.ngay_dat_hang) as date'),
                DB::raw('ROUND(SUM(tt.so_tien), 2) as amount')
            )
            ->groupBy(DB::raw('DATE(dh.ngay_dat_hang)'))
            ->orderBy('date', 'asc')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'amount' => (float) $r->amount,
            ])
            ->values()
            ->all();

        return [
            'totalPaidRevenue' => $totalPaidRevenue,
            'dailySeries' => $dailySeries,
        ];
    }

    /**
     * Báo cáo Đơn hàng: tổng số đơn, đơn hoàn tất, đơn hủy và tỷ lệ hoàn tất (RPT-02).
     *
     * @param string $fromDate
     * @param string $toDate
     * @return array<string, mixed>
     */
    public function getOrdersReport(string $fromDate, string $toDate): array
    {
        $ordersQuery = DB::table('DON_HANG')
            ->whereDate('ngay_dat_hang', '>=', $fromDate)
            ->whereDate('ngay_dat_hang', '<=', $toDate);

        $totalCount = (int) $ordersQuery->count();
        $completedCount = (int) (clone $ordersQuery)->where('trang_thai', OrderStatus::COMPLETED->value)->count();
        $cancelledCount = (int) (clone $ordersQuery)->where('trang_thai', OrderStatus::CANCELLED->value)->count();

        $completionRate = $totalCount > 0
            ? round(($completedCount / $totalCount) * 100, 2)
            : 0.0;

        return [
            'totalCount' => $totalCount,
            'completedCount' => $completedCount,
            'cancelledCount' => $cancelledCount,
            'completionRate' => $completionRate,
        ];
    }

    /**
     * Báo cáo Món bán chạy: top món ăn có số lượng bán cao nhất (RPT-03).
     *
     * @param string $fromDate
     * @param string $toDate
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getTopSellingDishes(string $fromDate, string $toDate, int $limit = 5): array
    {
        $topDishes = DB::table('CHI_TIET_DON_HANG as ct')
            ->join('DON_HANG as dh', 'ct.ma_don_hang', '=', 'dh.ma_don_hang')
            ->join('MON_AN as ma', 'ct.ma_mon_an', '=', 'ma.ma_mon_an')
            ->whereDate('dh.ngay_dat_hang', '>=', $fromDate)
            ->whereDate('dh.ngay_dat_hang', '<=', $toDate)
            ->where('dh.trang_thai', '!=', OrderStatus::CANCELLED->value)
            ->select(
                'ct.ma_mon_an as dishId',
                'ma.ten_mon_an as dishName',
                DB::raw('CAST(SUM(ct.so_luong) AS UNSIGNED) as totalSold'),
                DB::raw('ROUND(SUM(ct.so_luong * ct.don_gia), 2) as revenue')
            )
            ->groupBy('ct.ma_mon_an', 'ma.ten_mon_an')
            ->orderByDesc('totalSold')
            ->limit($limit)
            ->get()
            ->map(fn ($d) => [
                'dishId' => (int) $d->dishId,
                'dishName' => (string) $d->dishName,
                'totalSold' => (int) $d->totalSold,
                'revenue' => (float) $d->revenue,
            ])
            ->values()
            ->all();

        return $topDishes;
    }

    /**
     * Báo cáo Tình hình Đặt bàn: tổng lượt đặt, lượt xác nhận, tỷ lệ lấp đầy bàn (RPT-04).
     *
     * @param string $fromDate
     * @param string $toDate
     * @return array<string, mixed>
     */
    public function getReservationsReport(string $fromDate, string $toDate): array
    {
        $resQuery = DB::table('DAT_BAN')
            ->whereDate('thoi_gian', '>=', $fromDate)
            ->whereDate('thoi_gian', '<=', $toDate);

        $totalBookings = (int) $resQuery->count();
        $confirmedBookings = (int) (clone $resQuery)
            ->whereIn('trang_thai', [
                ReservationStatus::CONFIRMED->value,
                ReservationStatus::COMPLETED->value,
            ])
            ->count();

        $totalTables = max(DiningTable::count(), 1);
        $occupancyRatePercent = round(($confirmedBookings / $totalTables) * 100, 2);

        return [
            'totalBookings' => $totalBookings,
            'confirmedBookings' => $confirmedBookings,
            'occupancyRatePercent' => $occupancyRatePercent,
        ];
    }

    /**
     * Báo cáo tổng hợp Dashboard (RPT-01 -> RPT-04).
     *
     * @param string $fromDate
     * @param string $toDate
     * @param int $limit
     * @return array<string, mixed>
     */
    public function getDashboardReport(string $fromDate, string $toDate, int $limit = 5): array
    {
        return [
            'revenue' => $this->getRevenueReport($fromDate, $toDate),
            'orders' => $this->getOrdersReport($fromDate, $toDate),
            'topSellingDishes' => $this->getTopSellingDishes($fromDate, $toDate, $limit),
            'reservations' => $this->getReservationsReport($fromDate, $toDate),
        ];
    }
}

