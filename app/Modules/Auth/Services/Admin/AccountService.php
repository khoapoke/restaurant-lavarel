<?php

namespace App\Modules\Auth\Services\Admin;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\UserProfile;
use App\Modules\Core\Enums\AccountStatus;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountService
{
    /**
     * Get paginated accounts list with search and filters (USR-07, USR-08).
     *
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getAccounts(array $filters = []): LengthAwarePaginator
    {
        $query = Account::with(['role', 'userProfile']);

        // 1. Tìm kiếm theo keyword (username, email, fullName, phoneNumber)
        if (!empty($filters['keyword'])) {
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
        if (!empty($filters['status'])) {
            $statusInput = strtoupper(trim($filters['status']));
            if ($statusInput === 'ACTIVE' || $statusInput === AccountStatus::ACTIVE->value) {
                $query->where('trang_thai', AccountStatus::ACTIVE->value);
            } elseif ($statusInput === 'LOCKED' || $statusInput === AccountStatus::LOCKED->value) {
                $query->where('trang_thai', AccountStatus::LOCKED->value);
            }
        }

        // 3. Lọc theo vai trò (roleId)
        if (!empty($filters['roleId'])) {
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
     * @param Account $currentAdmin
     * @param int $accountId
     * @return Account
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
        if (!$targetAccount) {
            throw new ModelNotFoundException('Account not found');
        }

        // Cập nhật trạng thái bị khóa
        $targetAccount->trang_thai = AccountStatus::LOCKED;
        $targetAccount->save();

        // Xóa toàn bộ token trong personal_access_tokens để đăng xuất cưỡng bức ngay lập tức
        $targetAccount->tokens()->delete();

        return $targetAccount;
    }

    /**
     * Unlock an account (USR-10).
     *
     * @param int $accountId
     * @return Account
     * @throws ModelNotFoundException
     */
    public function unlockAccount(int $accountId): Account
    {
        $targetAccount = Account::with(['role', 'userProfile'])->find($accountId);
        if (!$targetAccount) {
            throw new ModelNotFoundException('Account not found');
        }

        // Cập nhật trạng thái mở khóa hoạt động
        $targetAccount->trang_thai = AccountStatus::ACTIVE;
        $targetAccount->save();

        return $targetAccount;
    }

    /**
     * Get account detail by ID (ADM-02).
     *
     * @param int $accountId
     * @return Account
     * @throws ModelNotFoundException
     */
    public function getAccountDetail(int $accountId): Account
    {
        $account = Account::with(['role', 'userProfile'])->find($accountId);
        if (!$account) {
            throw new ModelNotFoundException('Account not found');
        }

        return $account;
    }

    /**
     * Create staff account (ADM-03).
     *
     * @param array<string, mixed> $data
     * @return Account
     */
    public function createStaffAccount(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $staffRole = Role::firstOrCreate(['ten_vai_tro' => RoleType::STAFF->value]);

            $profile = UserProfile::create([
                'ho_ten' => trim($data['fullName']),
                'so_dien_thoai' => $data['phoneNumber'] ?? null,
                'ngay_sinh' => $data['birthDate'] ?? null,
                'dia_chi' => $data['address'] ?? null,
                'di_ung' => [],
                'che_do_an' => [],
                'so_dia_chi' => !empty($data['address']) ? [$data['address']] : [],
            ]);

            $account = Account::create([
                'ten_dang_nhap' => trim($data['username']),
                'mat_khau' => Hash::make($data['password']),
                'email' => trim($data['email']),
                'trang_thai' => AccountStatus::ACTIVE,
                'ma_nguoi_dung' => $profile->ma_nguoi_dung,
                'ma_vai_tro' => $staffRole->ma_vai_tro,
            ]);

            return $account->load(['role', 'userProfile']);
        });
    }

    /**
     * Update account and user profile info (ADM-04).
     *
     * @param int $accountId
     * @param array<string, mixed> $data
     * @return Account
     */
    public function updateAccount(int $accountId, array $data): Account
    {
        return DB::transaction(function () use ($accountId, $data) {
            $account = $this->getAccountDetail($accountId);

            if (!empty($data['email'])) {
                $account->email = trim($data['email']);
            }
            if (!empty($data['roleId'])) {
                $account->ma_vai_tro = (int) $data['roleId'];
            }
            if (!empty($data['status'])) {
                $statusInput = strtoupper(trim($data['status']));
                if ($statusInput === 'ACTIVE' || $statusInput === AccountStatus::ACTIVE->value) {
                    $account->trang_thai = AccountStatus::ACTIVE;
                } elseif ($statusInput === 'LOCKED' || $statusInput === AccountStatus::LOCKED->value) {
                    $account->trang_thai = AccountStatus::LOCKED;
                    $account->tokens()->delete();
                }
            }
            $account->save();

            $profile = $account->userProfile;
            if ($profile) {
                if (isset($data['fullName'])) {
                    $profile->ho_ten = trim($data['fullName']);
                }
                if (isset($data['phoneNumber'])) {
                    $profile->so_dien_thoai = trim($data['phoneNumber']);
                }
                if (array_key_exists('birthDate', $data)) {
                    $profile->ngay_sinh = $data['birthDate'];
                }
                if (array_key_exists('address', $data)) {
                    $profile->dia_chi = $data['address'];
                }
                $profile->save();
            }

            return $account->load(['role', 'userProfile']);
        });
    }

    /**
     * Update status (lock/unlock) of an account (ADM-05).
     *
     * @param Account $currentAdmin
     * @param int $accountId
     * @param string $status
     * @return Account
     * @throws HttpException
     */
    public function updateStatus(Account $currentAdmin, int $accountId, string $status): Account
    {
        $statusUpper = strtoupper(trim($status));
        if ($statusUpper === 'LOCKED' || $statusUpper === AccountStatus::LOCKED->value) {
            return $this->lockAccount($currentAdmin, $accountId);
        } elseif ($statusUpper === 'ACTIVE' || $statusUpper === AccountStatus::ACTIVE->value) {
            return $this->unlockAccount($accountId);
        }

        throw new HttpException(400, "Invalid status: {$status}");
    }
}

