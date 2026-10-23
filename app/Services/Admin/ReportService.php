<?php

namespace App\Services\Admin;

use App\Enums\AllocationType;
use App\Enums\ApprovalStatus;
use App\Enums\CancellationMoneyDecision;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\OrderLineType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PosSessionStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ReservationStatus;
use App\Enums\SessionEndType;
use App\Enums\TableStatus;
use App\Models\ApprovalRequest;
use App\Models\DiningTable;
use App\Models\MoneyTransaction;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Payments\PaymentLedgerService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * Báo cáo tài chính tách bạch (ADR-023). Công thức:
     * - salesValue: tổng giá trị đơn không hủy (đơn giá snapshot × số lượng tính tiền + phí giao), theo ngày đặt.
     * - moneyReceived: tổng khoản tiền thực nhận theo NGÀY NHẬN (GIAO_DICH_TIEN.nhan_luc); dữ liệu cũ thiếu
     *   ngày nhận được tách riêng ở undatedLegacyReceived, không gán bừa vào kỳ.
     * - appliedToSales: phần tiền đã áp vào đơn theo ngày áp (doanh thu đã thu).
     * - refunded: tiền đã ghi nhận hoàn theo NGÀY HOÀN; undatedLegacyRefunded tách riêng.
     * - netReceived = moneyReceived − refunded.
     * - unallocatedBalance / refundDueBalance / outstandingBalance: số dư hiện tại (không theo kỳ).
     * - voidedValue, waivedValue, remakeValue, writeOffValue: giảm trừ và thất thu trong kỳ.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<string, mixed>
     */
    private function finance(Carbon $from, Carbon $to, Collection $orders): array
    {
        $ledger = app(PaymentLedgerService::class);
        $received = MoneyTransaction::query()->whereBetween('nhan_luc', [$from, $to])->get();
        $refunds = Refund::query()->whereBetween('hoan_luc', [$from, $to])->get();
        // Tiền áp vào đơn sau đó bị hủy (trừ khi quản lý quyết định giữ tiền) không phải doanh thu: nằm ở refundDueBalance.
        $countsAsSales = fn (PaymentAllocation $allocation): bool => $allocation->order
            && ($allocation->order->trang_thai !== OrderStatus::CANCELLED || $allocation->order->quyet_dinh_tien_huy === CancellationMoneyDecision::RETAINED->value);
        $applied = PaymentAllocation::query()
            ->with(['moneyTransaction', 'order'])
            ->where('loai', AllocationType::APPLY->value)
            ->whereBetween('tao_luc', [$from, $to])
            ->get()
            ->filter($countsAsSales);
        $reversals = PaymentAllocation::query()
            ->with(['moneyTransaction', 'order'])
            ->where('loai', AllocationType::REFUND_REVERSAL->value)
            ->whereBetween('tao_luc', [$from, $to])
            ->get()
            ->filter($countsAsSales);

        $salesOrders = $orders->reject(fn (Order $order): bool => in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::DRAFT], true));
        $periodItems = $orders->flatMap->items;
        $voided = $periodItems->filter(fn ($item) => in_array($item->trang_thai_bep, [KitchenItemStatus::CANCELLED, KitchenItemStatus::REJECTED], true)
            && $item->loai_dong !== OrderLineType::REMAKE);

        $openTransactions = MoneyTransaction::query()->with('refunds')->where('trang_thai_doi_soat', '!=', ReconciliationStatus::RESOLVED->value)->get();
        $refundDueOrders = Order::query()
            ->whereHas('payment', fn ($query) => $query->where('so_tien_da_thu', '>', 0))
            ->get()
            ->filter(fn (Order $order) => $ledger->refundDue($order) > 0);
        $writtenOffOrderIds = ServiceSession::query()->where('kieu_ket_thuc', SessionEndType::WRITE_OFF->value)
            ->with('orders')->get()->flatMap(fn ($session) => $session->orders->pluck('ma_don_hang'))->all();

        $appliedByMethod = $applied->groupBy(fn ($allocation) => $allocation->moneyTransaction?->phuong_thuc?->name ?? 'UNKNOWN')
            ->map(fn ($rows) => round((float) $rows->sum('so_tien'), 2));
        $appliedTotal = round((float) $applied->sum('so_tien'), 2);

        return [
            'formula' => 'netReceived = moneyReceived − refunded; appliedToSales = tiền đã áp vào đơn; unallocatedBalance = tiền đã nhận chưa áp/chưa hoàn',
            // Giá trị bán sau giảm trừ (bồi hoàn sau thanh toán làm giảm khoản phải thu của đơn).
            'salesValue' => round((float) $salesOrders->sum(fn (Order $order) => $order->payableAmount()), 2),
            'compensationValue' => round((float) $salesOrders->sum(fn (Order $order) => (float) ($order->so_tien_giam_tru ?? 0)), 2),
            'cancelledOrdersCount' => $orders->where('trang_thai', OrderStatus::CANCELLED)->count(),
            'cancelledOrdersValue' => round((float) $orders->where('trang_thai', OrderStatus::CANCELLED)->flatMap->items->sum(fn ($item) => (float) $item->don_gia * (int) $item->so_luong), 2),
            'moneyReceived' => round((float) $received->sum('so_tien'), 2),
            'moneyReceivedByMethod' => [
                'CASH' => round((float) $received->filter(fn ($row) => $row->phuong_thuc === PaymentMethod::CASH)->sum('so_tien'), 2),
                'BANK_TRANSFER_QR' => round((float) $received->filter(fn ($row) => $row->phuong_thuc === PaymentMethod::BANK_TRANSFER_QR)->sum('so_tien'), 2),
            ],
            'undatedLegacyReceived' => round((float) MoneyTransaction::query()->whereNull('nhan_luc')->sum('so_tien'), 2),
            'appliedToSales' => round($appliedTotal + (float) $reversals->sum('so_tien'), 2),
            'appliedByMethod' => [
                'CASH' => (float) ($appliedByMethod['CASH'] ?? 0),
                'BANK_TRANSFER_QR' => (float) ($appliedByMethod['BANK_TRANSFER_QR'] ?? 0),
            ],
            'averageOrderValue' => $salesOrders->count() > 0 ? round((float) $salesOrders->avg(fn (Order $order) => $order->payableAmount()), 2) : 0,
            'refunded' => round((float) $refunds->sum('so_tien'), 2),
            'undatedLegacyRefunded' => round((float) Refund::query()->whereNull('hoan_luc')->sum('so_tien'), 2),
            'netReceived' => round((float) $received->sum('so_tien') - (float) $refunds->sum('so_tien'), 2),
            'unallocatedBalance' => round((float) $openTransactions->sum(fn (MoneyTransaction $row) => $row->unallocatedAmount()), 2),
            'refundDueBalance' => round((float) $refundDueOrders->sum(fn (Order $order) => $ledger->refundDue($order)), 2),
            'outstandingBalance' => round((float) $salesOrders->reject(fn (Order $order) => in_array($order->ma_don_hang, $writtenOffOrderIds, true))
                ->sum(fn (Order $order) => $ledger->outstanding($order)), 2),
            'voidedValue' => round((float) $voided->sum(fn ($item) => (float) $item->don_gia * ((int) $item->so_luong - (int) ($item->so_luong_tinh_tien ?? 0))), 2),
            'waivedValue' => round((float) $periodItems->where('mien_tien', true)->sum(fn ($item) => (float) $item->don_gia * ((int) $item->so_luong - (int) ($item->so_luong_tinh_tien ?? 0))), 2),
            'remakeValue' => round((float) $periodItems->filter(fn ($item) => $item->loai_dong === OrderLineType::REMAKE)->sum(fn ($item) => (float) $item->don_gia * (int) $item->so_luong), 2),
            'writeOffValue' => round((float) ServiceSession::query()->where('kieu_ket_thuc', SessionEndType::WRITE_OFF->value)->whereBetween('dong_luc', [$from, $to])->sum('so_tien_that_thu'), 2),
            'receivedByDay' => $received->filter(fn ($row) => $row->nhan_luc)->groupBy(fn ($row) => $row->nhan_luc->toDateString())->map(fn ($rows) => round((float) $rows->sum('so_tien'), 2)),
            'refundDueOrders' => $refundDueOrders->count(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function dashboard(array $filters): array
    {
        $from = Carbon::parse($filters['fromDate'] ?? now()->subDays(6)->toDateString())->startOfDay();
        $to = Carbon::parse($filters['toDate'] ?? now()->toDateString())->endOfDay();

        $orders = Order::query()
            ->with(['payment', 'items'])
            ->whereBetween('ngay_dat_hang', [$from, $to])
            ->get();

        $finance = $this->finance($from, $to, $orders);
        $receivedByDay = $finance['receivedByDay'];

        $dailySeries = collect(CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'amount' => (float) ($receivedByDay[$date->toDateString()] ?? 0),
            ])->values();

        $completedOrders = $orders->where('trang_thai', OrderStatus::COMPLETED);
        $cancelledOrders = $orders->where('trang_thai', OrderStatus::CANCELLED);
        $totalOrders = $orders->count();

        $topSellingDishes = $completedOrders
            ->flatMap->items
            ->groupBy('ma_mon_an')
            ->map(function ($items): array {
                $first = $items->first();
                $produced = $items->filter(fn ($item) => in_array($item->trang_thai_bep, [KitchenItemStatus::READY, KitchenItemStatus::SERVED], true));

                return [
                    'dishId' => (int) $first->ma_mon_an,
                    'dishName' => $first->ten_mon_snapshot,
                    // Bán = số phần tính tiền cho khách (không gồm làm lại miễn phí, món hủy/miễn tiền).
                    'totalSold' => (int) $items->sum(fn ($item) => (int) ($item->so_luong_tinh_tien ?? $item->so_luong)),
                    'revenue' => round((float) $items->sum(fn ($item) => $item->billedAmount()), 2),
                    // Chế biến = số phần bếp đã làm xong (gồm phần làm lại, phần được miễn tiền).
                    'preparedQuantity' => (int) $produced->sum('so_luong'),
                    'freeQuantity' => (int) $produced->sum(fn ($item) => max(0, (int) $item->so_luong - (int) ($item->so_luong_tinh_tien ?? $item->so_luong))),
                ];
            })
            ->filter(fn (array $row) => $row['totalSold'] > 0)
            ->sortByDesc('totalSold')->take(5)->values();

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

        $billableOrders = $orders->reject(fn (Order $order): bool => in_array($order->trang_thai, [OrderStatus::CANCELLED, OrderStatus::DRAFT], true));
        $ordersByType = $billableOrders->countBy(fn (Order $order): string => $order->loai_phuc_vu->name);

        $handoffOrders = Order::query()
            ->where('trang_thai', OrderStatus::READY->value)
            ->where('loai_phuc_vu', '!=', FulfillmentType::DINE_IN->value)
            ->get();

        $attention = [
            'pendingOrders' => Order::query()->where('trang_thai', OrderStatus::PENDING_CONFIRMATION->value)->count(),
            'readyForHandoff' => Order::query()
                ->where('trang_thai', OrderStatus::READY->value)
                ->where('loai_phuc_vu', '!=', FulfillmentType::DINE_IN->value)
                ->count(),
            'deliveryFailed' => $handoffOrders->where('trang_thai_giao_nhan', FulfillmentStatus::DELIVERY_FAILED)->count(),
            'driverWaitTooLong' => $handoffOrders->filter(fn (Order $order): bool => $order->driverWaitTooLong())->count(),
            'pickupOverdue' => $handoffOrders->filter(fn (Order $order): bool => $order->pickupOverdue())->count(),
            'refundsPending' => $finance['refundDueOrders'],
            'transactionsToReview' => MoneyTransaction::query()->where('trang_thai_doi_soat', ReconciliationStatus::NEEDS_REVIEW->value)->count(),
            'approvalsPending' => ApprovalRequest::query()->where('trang_thai', ApprovalStatus::PENDING->value)->count(),
            'reservationsToConfirm' => Reservation::query()
                ->where('trang_thai', ReservationStatus::PENDING->value)
                ->where('thoi_gian', '>=', now())
                ->count(),
        ];

        return [
            'period' => ['fromDate' => $from->toDateString(), 'toDate' => $to->toDateString()],
            // Tương thích ngược: "doanh thu đã thu" = tiền đã áp vào đơn bán hàng theo ngày áp.
            'revenue' => [
                'totalPaidRevenue' => $finance['appliedToSales'],
                'refundedAmount' => $finance['refunded'],
                'averageOrderValue' => $finance['averageOrderValue'],
                'byMethod' => $finance['appliedByMethod'],
                'dailySeries' => $dailySeries,
            ],
            'finance' => collect($finance)->except(['receivedByDay', 'refundDueOrders'])->all(),
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
