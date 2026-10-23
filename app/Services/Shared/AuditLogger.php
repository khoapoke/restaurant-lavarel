<?php

namespace App\Services\Shared;

use App\Models\Account;
use App\Models\AuditLog;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Ghi nhật ký thao tác nhạy cảm (ADR-023). Chỉ ghi thêm; không ghi token, mật khẩu hay
 * secret — các khóa nhạy cảm bị loại khỏi before/after trước khi lưu.
 */
class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'mat_khau', 'token', 'token_hash', 'secret', 'signature', 'visitToken', 'ma_tham_gia', 'joinCode', 'ma_qr'];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(?Account $actor, string $action, string $entity, int $entityId, ?string $reason = null, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::create([
            'ma_tai_khoan' => $actor?->ma_tai_khoan,
            'vai_tro' => $actor?->roleCode(),
            'hanh_dong' => $action,
            'doi_tuong' => $entity,
            'ma_doi_tuong' => $entityId,
            'ly_do' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'truoc' => $before !== null ? $this->scrub($before) : null,
            'sau' => $after !== null ? $this->scrub($after) : null,
            'tao_luc' => now(),
        ]);
    }

    /** @return Collection<int, AuditLog> */
    public function forEntity(string $entity, int $entityId, int $limit = 50): Collection
    {
        return AuditLog::query()
            ->with('actor.userProfile')
            ->where('doi_tuong', $entity)
            ->where('ma_doi_tuong', $entityId)
            ->orderByDesc('ma_nhat_ky')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<array{0: string, 1: int}>  $entities
     * @return Collection<int, AuditLog>
     */
    public function forEntities(array $entities, int $limit = 80): Collection
    {
        if ($entities === []) {
            return collect();
        }

        return AuditLog::query()
            ->with('actor.userProfile')
            ->where(function ($query) use ($entities): void {
                foreach ($entities as [$entity, $entityId]) {
                    $query->orWhere(fn ($nested) => $nested->where('doi_tuong', $entity)->where('ma_doi_tuong', $entityId));
                }
            })
            ->orderByDesc('ma_nhat_ky')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        // Lọc đệ quy: bí mật nằm trong mảng lồng (payload, dữ liệu yêu cầu duyệt) cũng bị loại.
        return collect(Arr::except($data, self::SENSITIVE_KEYS))
            ->map(fn ($value) => match (true) {
                is_array($value) => $this->scrub($value),
                $value instanceof \BackedEnum => $value->name,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:sP'),
                default => $value,
            })
            ->all();
    }
}
