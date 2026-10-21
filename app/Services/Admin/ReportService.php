<?php

namespace App\Services\Admin;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ServiceSession;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class ReportService
{
    /** @param array<string, mixed> $filters */
    public function dashboard(array $filters): array
    {
        $from = Carbon::parse($filters['fromDate'] ?? now()->subDays(6)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['toDate'] ?? now()->toDateString())->endOfDay();

        $orders = Order::query()
            ->with(['payment', 'items'])
            ->whereBetween('ngay_dat_hang', [$from, $to])
            ->get();

        $paidPayments = Payment::query()
            ->with('order')
            ->where('trang_thai', PaymentStatus::PAID->value)
            ->whereHas('order', fn ($query) => $query->whereBetween('ngay_dat_hang', [$from, $to]))
            ->get();

        $revenueByDay = $paidPayments
            ->groupBy(fn (Payment $payment): string => $payment->order->ngay_dat_hang->toDateString())
            ->map(fn ($payments): float => round((float) $payments->sum('so_tien'), 2));

        $dailySeries = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'amount' => (float) ($revenueByDay[$date->toDateString()] ?? 0),
            ])->values();

        $completedOrders = $orders->where('trang_thai', OrderStatus::COMPLETED);
        $cancelledOrders = $orders->where('trang_thai', OrderStatus::CANCELLED);
        $totalOrders = $orders->count();

        $topSellingDishes = $completedOrders
            ->flatMap->items
            ->groupBy('ma_mon_an')
            ->map(function ($items): array {
                $first = $items->first();
                $totalSold = (int) $items->sum('so_luong');

                return [
                    'dishId' => (int) $first->ma_mon_an,
                    'dishName' => $first->ten_mon_snapshot,
                    'totalSold' => $totalSold,
                    'revenue' => (float) $items->sum(fn ($item) => (int) $item->so_luong * (float) $item->don_gia),
                ];
            })->sortByDesc('totalSold')->take(5)->values();

        $reservations = Reservation::query()
            ->whereBetween('thoi_gian', [$from, $to])
            ->get();
        $confirmedBookings = $reservations->whereIn('trang_thai', [
            ReservationStatus::CONFIRMED,
            ReservationStatus::COMPLETED,
        ])->count();

        $tableCounts = DiningTable::query()
            ->get()
            ->countBy(fn (DiningTable $table): string => $table->trang_thai->name);

        $revenueByMethod = $paidPayments
            ->groupBy(fn (Payment $payment): string => $payment->phuong_thuc->name)
            ->map(fn ($payments): float => round((float) $payments->sum('so_tien'), 2));

        $refundedAmount = (float) Payment::query()
            ->where('trang_thai', PaymentStatus::REFUNDED->value)
            ->whereHas('order', fn ($query) => $query->whereBetween('ngay_dat_hang', [$from, $to]))
            ->sum('so_tien');

        $billableOrders = $orders->reject(fn (Order $order): bool => in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::DRAFT], true));
        $ordersByType = $billableOrders->countBy(fn (Order $order): string => $order->loai_phuc_vu->name);

        $attention = [
            'pendingOrders' => Order::query()->where('trang_thai', OrderStatus::PENDING_CONFIRMATION->value)->count(),
            'readyForHandoff' => Order::query()
                ->where('trang_thai', OrderStatus::READY->value)
                ->where('loai_phuc_vu', '!=', FulfillmentType::DINE_IN->value)
                ->count(),
            'refundsPending' => Payment::query()
                ->where('trang_thai', PaymentStatus::PAID->value)
                ->whereHas('order', fn ($query) => $query->where('trang_thai', OrderStatus::CANCELLED->value))
                ->count(),
            'reservationsToConfirm' => Reservation::query()
                ->where('trang_thai', ReservationStatus::PENDING->value)
                ->where('thoi_gian', '>=', now())
                ->count(),
        ];

        return [
            'period' => ['fromDate' => $from->toDateString(), 'toDate' => $to->toDateString()],
            'revenue' => [
                'totalPaidRevenue' => round((float) $paidPayments->sum('so_tien'), 2),
                'refundedAmount' => round($refundedAmount, 2),
                'averageOrderValue' => $paidPayments->count() > 0 ? round((float) $paidPayments->avg('so_tien'), 2) : 0,
                'byMethod' => [
                    'CASH' => (float) ($revenueByMethod['CASH'] ?? 0),
                    'BANK_TRANSFER_QR' => (float) ($revenueByMethod['BANK_TRANSFER_QR'] ?? 0),
                ],
                'dailySeries' => $dailySeries,
            ],
            'orders' => [
                'totalCount' => $totalOrders,
                'completedCount' => $completedOrders->count(),
                'cancelledCount' => $cancelledOrders->count(),
                'pendingCount' => $orders->where('trang_thai', OrderStatus::PENDING_CONFIRMATION)->count(),
                'completionRate' => $totalOrders > 0 ? round($completedOrders->count() / $totalOrders * 100, 2) : 0,
                'byFulfillmentType' => [
                    'DELIVERY' => (int) ($ordersByType['DELIVERY'] ?? 0),
                    'PICKUP' => (int) ($ordersByType['PICKUP'] ?? 0),
                    'DINE_IN' => (int) ($ordersByType['DINE_IN'] ?? 0),
                ],
            ],
            'attention' => $attention,
            'topSellingDishes' => $topSellingDishes,
            'reservations' => [
                'totalBookings' => $reservations->count(),
                'confirmedBookings' => $confirmedBookings,
                'pendingBookings' => $reservations->where('trang_thai', ReservationStatus::PENDING)->count(),
                'occupancyRatePercent' => $reservations->count() > 0
                    ? round($confirmedBookings / $reservations->count() * 100, 2)
                    : 0,
            ],
            'operations' => [
                'activeSessions' => ServiceSession::query()->whereIn('trang_thai', [
                    PosSessionStatus::OPEN->value,
                    PosSessionStatus::AWAITING_PAYMENT->value,
                ])->count(),
                'tables' => [
                    'total' => $tableCounts->sum(),
                    'available' => (int) ($tableCounts[TableStatus::AVAILABLE->name] ?? 0),
                    'reserved' => (int) ($tableCounts[TableStatus::RESERVED->name] ?? 0),
                    'occupied' => (int) ($tableCounts[TableStatus::OCCUPIED->name] ?? 0),
                    'cleaning' => (int) ($tableCounts[TableStatus::CLEANING->name] ?? 0),
                    'maintenance' => (int) ($tableCounts[TableStatus::MAINTENANCE->name] ?? 0),
                ],
            ],
        ];
    }
}
