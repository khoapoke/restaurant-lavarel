<?php

namespace App\Http\Controllers\Api\Staff;

use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelPosSessionRequest;
use App\Http\Requests\Staff\ChangeGuestCountRequest;
use App\Http\Requests\Staff\ChangeSessionTablesRequest;
use App\Http\Requests\Staff\OpenPosSessionRequest;
use App\Http\Resources\Shared\AuditLogResource;
use App\Http\Resources\Staff\PosSessionResource;
use App\Http\Resources\Staff\PosTableResource;
use App\Models\DiningTable;
use App\Models\ServiceSession;
use App\Services\Shared\AuditLogger;
use App\Services\Staff\PosSessionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PosSessionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PosSessionService $posSessionService) {}

    public function floor(): JsonResponse
    {
        $tables = $this->posSessionService->getFloor();

        return $this->successResponse([
            'syncedAt' => now()->toIso8601String(),
            'summary' => [
                'total' => $tables->count(),
                'available' => $tables->where('trang_thai', TableStatus::AVAILABLE)->count(),
                'reserved' => $tables->where('trang_thai', TableStatus::RESERVED)->count(),
                'occupied' => $tables->where('trang_thai', TableStatus::OCCUPIED)->count(),
                'cleaning' => $tables->where('trang_thai', TableStatus::CLEANING)->count(),
                'maintenance' => $tables->where('trang_thai', TableStatus::MAINTENANCE)->count(),
            ],
            'items' => PosTableResource::collection($tables),
        ], 'POS floor retrieved successfully');
    }

    public function show(int $sessionId): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->getSession($sessionId)),
            'POS session retrieved successfully'
        );
    }

    public function store(OpenPosSessionRequest $request): JsonResponse
    {
        $result = $this->posSessionService->open($request->user(), $request->validated());

        return $this->successResponse(
            new PosSessionResource($result['session']),
            $result['created'] ? 'POS session opened successfully' : 'Existing POS session returned',
            $result['created'] ? 201 : 200
        );
    }

    public function cancel(CancelPosSessionRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->cancel($session, $request->validated('reason'), $request->user())),
            'POS session cancelled successfully'
        );
    }

    public function changeGuestCount(ChangeGuestCountRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->changeGuestCount($session, $request->user(), (int) $request->validated('guestCount'), $request->validated('reason'))),
            'Guest count updated'
        );
    }

    public function changeTables(ChangeSessionTablesRequest $request, ServiceSession $session): JsonResponse
    {
        return $this->successResponse(
            new PosSessionResource($this->posSessionService->changeTables(
                $session,
                $request->user(),
                $request->validated('tableIds'),
                $request->validated('reason'),
                $request->validated('releaseAs') ?? 'CLEANING'
            )),
            'Session tables updated'
        );
    }

    /** Nhật ký thao tác của phiên: phiên, món, hóa đơn, yêu cầu duyệt (chỉ đọc). */
    public function history(ServiceSession $session): JsonResponse
    {
        $session->load(['orders.items', 'invoice', 'approvals']);
        $entities = [['SESSION', (int) $session->ma_phien]];
        foreach ($session->orders as $order) {
            $entities[] = ['ORDER', (int) $order->ma_don_hang];
            foreach ($order->items as $item) {
                $entities[] = ['ORDER_ITEM', (int) $item->ma_chi_tiet];
            }
        }
        if ($session->invoice) {
            $entities[] = ['INVOICE', (int) $session->invoice->ma_hoa_don];
        }
        foreach ($session->approvals as $approval) {
            $entities[] = ['APPROVAL', (int) $approval->ma_yeu_cau_duyet];
        }
        if ($session->ma_dat_ban) {
            $entities[] = ['RESERVATION', (int) $session->ma_dat_ban];
        }

        return $this->successResponse(
            AuditLogResource::collection(app(AuditLogger::class)->forEntities($entities)),
            'Session history retrieved'
        );
    }

    public function completeCleaning(DiningTable $table): JsonResponse
    {
        return $this->successResponse(
            new PosTableResource($this->posSessionService->completeCleaning($table)),
            'Table cleaning completed successfully'
        );
    }
}
