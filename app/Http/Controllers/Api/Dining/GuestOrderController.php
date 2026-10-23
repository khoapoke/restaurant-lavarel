<?php

namespace App\Http\Controllers\Api\Dining;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dining\SubmitTableOrderRequest;
use App\Models\KitchenTicket;
use App\Services\Dining\GuestAccessService;
use App\Services\Dining\LiveStateService;
use App\Services\Dining\TableOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class GuestOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GuestAccessService $guestAccess,
        private readonly TableOrderService $tableOrders,
        private readonly LiveStateService $liveState,
    ) {}

    public function store(SubmitTableOrderRequest $request, string $tableToken): JsonResponse
    {
        $table = $this->guestAccess->resolveTable($tableToken);
        $visit = $this->guestAccess->requireVisit($table, $request->header(GuestTableController::VISIT_HEADER));
        $result = $this->tableOrders->submit(
            $table,
            $visit,
            $request->validated('items'),
            $request->validated('idempotencyKey'),
            $request->filled('expectedTotal') ? (float) $request->validated('expectedTotal') : null
        );
        $ticket = $result['ticket'];

        return $this->successResponse([
            'round' => [
                'roundNumber' => KitchenTicket::query()
                    ->where('ma_don_hang', $ticket->ma_don_hang)
                    ->where('ma_phieu_bep', '<=', $ticket->ma_phieu_bep)
                    ->count(),
                'sentAt' => $ticket->gui_luc?->toIso8601String(),
                'itemCount' => (int) $ticket->items->sum('so_luong'),
                'source' => $ticket->nguon->name,
            ],
            'created' => $result['created'],
            'live' => $this->liveState->snapshot($table, $visit->fresh()),
        ], $result['created'] ? 'Order sent to the kitchen' : 'Order round already received', $result['created'] ? 201 : 200);
    }
}
