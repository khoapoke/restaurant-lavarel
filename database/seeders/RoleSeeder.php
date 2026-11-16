<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Core\Enums\RoleType;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng VAI_TRO (Tier 0 - Độc lập, không chứa khóa ngoại).
     */
    public function run(): void
    {
        $roles = [
            RoleType::ADMIN->value,    // Quản trị viên
            RoleType::STAFF->value,    // Nhân viên
            RoleType::CUSTOMER->value, // Khách hàng
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['ten_vai_tro' => $roleName]);
        }
    }
}

