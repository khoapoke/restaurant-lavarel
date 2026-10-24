<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Services\Shared\SupportChatService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountService
{
    /**
     * Get paginated accounts list with search and filters (USR-07, USR-08).
     */
    public function getAccounts(array $filters = []): LengthAwarePaginator
    {
        $query = Account::with(['role', 'userProfile']);

        // 1. Tìm kiếm theo keyword (username, email, fullName, phoneNumber)
        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where(function (Builder $q) use ($keyword) {
                $q->where('ten_dang_nhap', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhereHas('userProfile', function (Builder $profileQuery) use ($keyword) {
                        $profileQuery->where('ho_ten', 'like', "%{$keyword}%")
                            ->orWhere('so_dien_thoai', 'like', "%{$keyword}%");
                    });
            });
        }

        // 2. Lọc theo trạng thái (status: ACTIVE / LOCKED hoặc Hoạt động / Bị khóa)
        if (! empty($filters['status'])) {
            $statusInput = strtoupper(trim($filters['status']));
            if ($statusInput === 'ACTIVE' || $statusInput === AccountStatus::ACTIVE->value) {
                $query->where('trang_thai', AccountStatus::ACTIVE->value);
            } elseif ($statusInput === 'LOCKED' || $statusInput === AccountStatus::LOCKED->value) {
                $query->where('trang_thai', AccountStatus::LOCKED->value);
            }
        }

        // 3. Lọc theo vai trò (roleId)
        if (! empty($filters['roleId'])) {
            $query->where('ma_vai_tro', (int) $filters['roleId']);
        }

        $perPage = (int) ($filters['perPage'] ?? 10);
        $page = (int) ($filters['page'] ?? 1);

        return $query->orderBy('ma_tai_khoan', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Lock an account and purge all active tokens (USR-09, Rule AUTH-LOCK).
     *
     * @throws HttpException
     * @throws ModelNotFoundException
     */
    public function lockAccount(Account $currentAdmin, int $accountId): Account
    {
        // Kiểm tra Rule AUTH-LOCK: Admin không được tự khóa chính mình
        if ((int) $currentAdmin->ma_tai_khoan === (int) $accountId) {
            throw new HttpException(400, 'Admin cannot lock their own account');
        }

        $targetAccount = Account::with(['role', 'userProfile'])->find($accountId);
        if (! $targetAccount) {
            throw new ModelNotFoundException('Account not found');
        }

        DB::transaction(function () use ($currentAdmin, $targetAccount): void {
            // Cập nhật trạng thái bị khóa
            $targetAccount->trang_thai = AccountStatus::LOCKED;
            $targetAccount->save();

            // Xóa toàn bộ token trong personal_access_tokens để đăng xuất cưỡng bức ngay lập tức
            $targetAccount->tokens()->delete();

            // Hội thoại hỗ trợ nhân viên này đang phụ trách trở về hàng chờ (ADR-029 bổ sung)
            if ($targetAccount->roleCode() !== 'CUSTOMER') {
                app(SupportChatService::class)->releaseAssignmentsOf($targetAccount, $currentAdmin);
            }
        });

        return $targetAccount;
    }

    /**
     * Unlock an account (USR-10).
     *
     * @throws ModelNotFoundException
     */
    public function unlockAccount(int $accountId): Account
    {
        $targetAccount = Account::with(['role', 'userProfile'])->find($accountId);
        if (! $targetAccount) {
            throw new ModelNotFoundException('Account not found');
        }

        // Cập nhật trạng thái mở khóa hoạt động
        $targetAccount->trang_thai = AccountStatus::ACTIVE;
        $targetAccount->save();

        return $targetAccount;
    }
}
