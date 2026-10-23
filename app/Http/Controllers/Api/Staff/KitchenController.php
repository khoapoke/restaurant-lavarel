<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\KitchenItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateKitchenItemStatusRequest;
use App\Http\Resources\Staff\KitchenItemResource;
use App\Models\OrderItem;
use App\Services\Staff\KitchenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class KitchenController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly KitchenService $kitchenService) {}

    public function index(): JsonResponse
    {
        return $this->successResponse([
            'syncedAt' => now()->toIso8601String(),
            'sla' => [
                'warningMinutes' => (int) config('dining.kitchen.warning_minutes'),
                'lateMinutes' => (int) config('dining.kitchen.late_minutes'),
            ],
            'items' => KitchenItemResource::collection($this->kitchenService->activeItems()),
            'upcoming' => $this->kitchenService->upcoming()->map(fn ($order) => [
                'orderId' => (int) $order->ma_don_hang,
                'fulfillmentType' => $order->loai_phuc_vu->name,
                'scheduledAt' => $order->thoi_gian_nhan_du_kien?->toIso8601String(),
                'releaseAt' => $order->thoi_gian_nhan_du_kien?->copy()->subMinutes((int) config('restaurant.fulfillment.scheduled_prep_lead_minutes', 30))->toIso8601String(),
                'recipientName' => $order->ten_nguoi_nhan,
                'items' => $order->items->map(fn ($item) => ['dishName' => $item->ten_mon_snapshot, 'quantity' => (int) $item->so_luong, 'note' => $item->ghi_chu])->values(),
            ])->values(),
            'undoWindowSeconds' => (int) config('restaurant.kitchen.undo_window_seconds', 120),
        ], 'Kitchen board retrieved successfully');
    }

    public function updateStatus(UpdateKitchenItemStatusRequest $request, OrderItem $item): JsonResponse
    {
        $updated = $this->kitchenService->updateStatus(
            $item,
            KitchenItemStatus::from($request->validated('status')),
            $request->user()
        );

        return $this->successResponse(new KitchenItemResource($updated), 'Kitchen item status updated');
    }
}
