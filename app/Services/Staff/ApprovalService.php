<?php

namespace App\Services\Staff;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Models\Account;
use App\Models\ApprovalRequest;
use App\Models\OrderItem;
use App\Models\Reservation;
use App\Models\ServiceSession;
use App\Services\Shared\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Yêu cầu duyệt (ADR-024): STAFF gửi yêu cầu cho thao tác vượt quyền; ADMIN duyệt bằng
 * chính phiên đăng nhập của mình (không nhập mật khẩu quản lý trên máy nhân viên). Khi
 * duyệt, thao tác được thực thi trong cùng transaction; lỗi nghiệp vụ giữ yêu cầu ở PENDING.
 */
class ApprovalService
{
    public function __construct(
        private readonly ItemCorrectionService $items,
        private readonly BillingService $billing,
        private readonly PosSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function request(ApprovalType $type, Account $requester, int $targetId, array $payload, string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($type, $requester, $targetId, $payload, $reason): ApprovalRequest {
            [$entity, $sessionId] = $this->target($type, $targetId, $payload);

            $existing = ApprovalRequest::query()
                ->where('loai', $type->value)
                ->where('doi_tuong', $entity)
                ->where('ma_doi_tuong', $targetId)
                ->where('trang_thai', ApprovalStatus::PENDING->value)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing->load(['requester.userProfile', 'approver.userProfile']);
            }

            $approval = ApprovalRequest::create([
                'loai' => $type,
                'trang_thai' => ApprovalStatus::PENDING,
                'doi_tuong' => $entity,
                'ma_doi_tuong' => $targetId,
                'ma_phien' => $sessionId,
                'du_lieu' => $payload,
                'ly_do' => $reason,
                'ma_nguoi_yeu_cau' => $requester->ma_tai_khoan,
                'tao_luc' => now(),
            ]);
            $this->audit->record($requester, 'APPROVAL_REQUESTED', 'APPROVAL', (int) $approval->ma_yeu_cau_duyet, $reason, null, ['type' => $type->name, 'target' => $targetId, 'payload' => $payload]);

            return $approval->load(['requester.userProfile', 'approver.userProfile']);
        });
    }

    public function approve(ApprovalRequest $approval, Account $admin, ?string $note = null): ApprovalRequest
    {
        if (! $admin->isAdmin()) {
            throw new HttpException(403, 'Only an administrator can approve this request.');
        }

        return DB::transaction(function () use ($approval, $admin, $note): ApprovalRequest {
            $locked = $this->lockPending($approval);
            $payload = $locked->du_lieu ?? [];

            match ($locked->loai) {
                ApprovalType::ITEM_VOID => $this->items->void(OrderItem::query()->findOrFail($locked->ma_doi_tuong), $admin, [
                    'reason' => $payload['reason'],
                    'note' => $locked->ly_do,
                    'quantity' => $payload['quantity'] ?? null,
                    'waive' => (bool) ($payload['waive'] ?? false),
                ]),
                ApprovalType::SESSION_WRITE_OFF => $this->billing->writeOff(ServiceSession::query()->findOrFail($locked->ma_doi_tuong), $admin, $locked->ly_do),
                ApprovalType::SEAT_OUTSIDE_WINDOW => $this->sessions->open($admin, [
                    'tableIds' => $payload['tableIds'],
                    'guestCount' => $payload['guestCount'],
                    'reservationId' => $locked->ma_doi_tuong,
                    'relocationReason' => $payload['relocationReason'] ?? null,
                    'overrideReason' => $locked->ly_do,
                    'idempotencyKey' => 'approval-'.$locked->ma_yeu_cau_duyet,
                ]),
            };

            $locked->update([
                'trang_thai' => ApprovalStatus::APPROVED,
                'ma_nguoi_duyet' => $admin->ma_tai_khoan,
                'ghi_chu_duyet' => $note,
                'xu_ly_luc' => now(),
            ]);
            $this->audit->record($admin, 'APPROVAL_APPROVED', 'APPROVAL', (int) $locked->ma_yeu_cau_duyet, $note, null, ['type' => $locked->loai->name, 'target' => (int) $locked->ma_doi_tuong]);

            return $locked->fresh(['requester.userProfile', 'approver.userProfile']);
        });
    }

    public function reject(ApprovalRequest $approval, Account $admin, string $note): ApprovalRequest
    {
        if (! $admin->isAdmin()) {
            throw new HttpException(403, 'Only an administrator can decline this request.');
        }

        return DB::transaction(function () use ($approval, $admin, $note): ApprovalRequest {
            $locked = $this->lockPending($approval);
            $locked->update([
                'trang_thai' => ApprovalStatus::REJECTED,
                'ma_nguoi_duyet' => $admin->ma_tai_khoan,
                'ghi_chu_duyet' => $note,
                'xu_ly_luc' => now(),
            ]);
            $this->audit->record($admin, 'APPROVAL_REJECTED', 'APPROVAL', (int) $locked->ma_yeu_cau_duyet, $note);

            return $locked->fresh(['requester.userProfile', 'approver.userProfile']);
        });
    }

    public function cancel(ApprovalRequest $approval, Account $requester): ApprovalRequest
    {
        return DB::transaction(function () use ($approval, $requester): ApprovalRequest {
            $locked = $this->lockPending($approval);
            if ((int) $locked->ma_nguoi_yeu_cau !== (int) $requester->ma_tai_khoan && ! $requester->isAdmin()) {
                throw new HttpException(403, 'Only the requester can withdraw this request.');
            }
            $locked->update(['trang_thai' => ApprovalStatus::CANCELLED, 'xu_ly_luc' => now()]);
            $this->audit->record($requester, 'APPROVAL_WITHDRAWN', 'APPROVAL', (int) $locked->ma_yeu_cau_duyet);

            return $locked->fresh(['requester.userProfile', 'approver.userProfile']);
        });
    }

    /** @param array{status?: ?string, sessionId?: ?int} $filters */
    public function list(array $filters = []): Collection
    {
        return ApprovalRequest::query()
            ->with(['requester.userProfile', 'approver.userProfile', 'serviceSession.tables'])
            ->when($filters['status'] ?? 'PENDING', fn ($query, $status) => $query->where('trang_thai', strtoupper($status)))
            ->when($filters['sessionId'] ?? null, fn ($query, $sessionId) => $query->where('ma_phien', $sessionId))
            ->orderByDesc('ma_yeu_cau_duyet')
            ->limit(50)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: ?int}
     */
    private function target(ApprovalType $type, int $targetId, array $payload): array
    {
        return match ($type) {
            ApprovalType::ITEM_VOID => (function () use ($targetId, $payload): array {
                $item = OrderItem::query()->with('order')->findOrFail($targetId);
                if (! $this->items->requiresApproval($item)) {
                    throw new HttpException(409, 'This item has not been started by the kitchen. Cancel it directly.');
                }
                if (empty($payload['reason'])) {
                    throw new HttpException(422, 'Choose a reason for cancelling the item.');
                }

                return ['ORDER_ITEM', $item->order?->ma_phien];
            })(),
            ApprovalType::SESSION_WRITE_OFF => ['SESSION', ServiceSession::query()->findOrFail($targetId)->ma_phien],
            ApprovalType::SEAT_OUTSIDE_WINDOW => (function () use ($targetId, $payload): array {
                Reservation::query()->findOrFail($targetId);
                if (empty($payload['tableIds']) || empty($payload['guestCount'])) {
                    throw new HttpException(422, 'Choose the tables and the number of guests to seat.');
                }

                return ['RESERVATION', null];
            })(),
        };
    }

    private function lockPending(ApprovalRequest $approval): ApprovalRequest
    {
        $locked = ApprovalRequest::query()->lockForUpdate()->findOrFail($approval->ma_yeu_cau_duyet);
        if ($locked->trang_thai !== ApprovalStatus::PENDING) {
            throw new HttpException(409, 'This request has already been handled.');
        }

        return $locked;
    }
}
