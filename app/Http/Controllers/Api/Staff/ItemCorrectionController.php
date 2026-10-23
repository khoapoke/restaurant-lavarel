<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ExchangeOrderItemRequest;
use App\Http\Requests\Staff\ReasonOnlyRequest;
use App\Http\Requests\Staff\RejectKitchenItemRequest;
use App\Http\Requests\Staff\RemakeOrderItemRequest;
use App\Http\Requests\Staff\SplitOrderItemRequest;
use App\Http\Requests\Staff\VoidOrderItemRequest;
use App\Http\Resources\Staff\KitchenItemResource;
use App\Http\Resources\Staff\PosOrderItemResource;
use App\Models\OrderItem;
use App\Services\Staff\ItemCorrectionService;
use App\Services\Staff\KitchenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hủy / miễn tiền / từ chối / làm lại / đổi / tách món và hoàn tác bước bếp (ADR-024). */
class ItemCorrectionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ItemCorrectionService $corrections,
        private readonly KitchenService $kitchen,
    ) {}

    public function void(VoidOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new PosOrderItemResource($this->corrections->void($item, $request->user(), $request->validated())), 'Item cancelled');
    }

    public function waive(ReasonOnlyRequest $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new PosOrderItemResource($this->corrections->waive($item, $request->user(), $request->validated('note'))), 'Item charge waived');
    }

    public function remake(RemakeOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new PosOrderItemResource($this->corrections->remake($item, $request->user(), $request->validated())), 'Remake sent to the kitchen', 201);
    }

    public function exchange(ExchangeOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new PosOrderItemResource($this->corrections->exchange($item, $request->user(), $request->validated())), 'Item exchanged', 201);
    }

    public function split(SplitOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new PosOrderItemResource($this->corrections->split($item, $request->user(), (int) $request->validated('quantity'))), 'Item split', 201);
    }

    public function reject(RejectKitchenItemRequest $request, OrderItem $item): JsonResponse
    {
        $rejected = $this->corrections->reject($item, $request->user(), $request->validated());

        return $this->successResponse(new KitchenItemResource($rejected->load('order.serviceSession.tables')), 'Item rejected by the kitchen');
    }

    public function undo(Request $request, OrderItem $item): JsonResponse
    {
        return $this->successResponse(new KitchenItemResource($this->kitchen->undo($item, $request->user())), 'Kitchen step undone');
    }
}
