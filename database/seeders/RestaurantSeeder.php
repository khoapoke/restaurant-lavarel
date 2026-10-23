<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\FulfillmentType;
use App\Enums\KitchenItemStatus;
use App\Enums\MoneySource;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PosSessionStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleType;
use App\Enums\TableStatus;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\ServiceSession;
use App\Models\UserProfile;
use App\Services\Payments\PaymentLedgerService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RestaurantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Roles (VAI_TRO)
        $roleAdmin = Role::create([
            'ten_vai_tro' => RoleType::ADMIN->value, // Quản trị viên
        ]);
        $roleStaff = Role::create([
            'ten_vai_tro' => RoleType::STAFF->value, // Nhân viên
        ]);
        $roleCustomer = Role::create([
            'ten_vai_tro' => RoleType::CUSTOMER->value, // Khách hàng
        ]);

        // 2. User Profiles (NGUOI_DUNG)
        $profileAdmin = UserProfile::create([
            'ho_ten' => 'Nguyễn Văn Quản Trị',
            'ngay_sinh' => '1990-01-01',
            'dia_chi' => '123 Đường Lê Lợi, Quận 1, TP.HCM',
            'so_dien_thoai' => '0901234567',
            'di_ung' => [],
            'che_do_an' => [],
            'so_dia_chi' => ['123 Đường Lê Lợi, Quận 1, TP.HCM'],
        ]);

        $profileStaff = UserProfile::create([
            'ho_ten' => 'Trần Thị Thu Ngân',
            'ngay_sinh' => '1995-05-10',
            'dia_chi' => '456 Đường Nguyễn Huệ, Quận 1, TP.HCM',
            'so_dien_thoai' => '0912345678',
            'di_ung' => [],
            'che_do_an' => [],
            'so_dia_chi' => ['456 Đường Nguyễn Huệ, Quận 1, TP.HCM'],
        ]);

        $profileKh1 = UserProfile::create([
            'ho_ten' => 'Lê Hoàng Hùng',
            'ngay_sinh' => '1998-03-20',
            'dia_chi' => '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
            'so_dien_thoai' => '0923456789',
            'di_ung' => ['PEANUT', 'SHELLFISH'], // Dị ứng đậu phộng, hải sản vỏ
            'che_do_an' => ['KETO'],
            'so_dia_chi' => [
                '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
                'Tòa nhà Bitexco, Bến Nghé, Quận 1, TP.HCM',
            ],
        ]);

        $profileKh2 = UserProfile::create([
            'ho_ten' => 'Phạm Thị Mai',
            'ngay_sinh' => '2001-11-12',
            'dia_chi' => '12 Võ Văn Tần, Quận 3, TP.HCM',
            'so_dien_thoai' => '0934567890',
            'di_ung' => [],
            'che_do_an' => ['VEGETARIAN'],
            'so_dia_chi' => ['12 Võ Văn Tần, Quận 3, TP.HCM'],
        ]);

        $profileKhLocked = UserProfile::create([
            'ho_ten' => 'Đỗ Vi Phạm',
            'ngay_sinh' => '1992-07-07',
            'dia_chi' => '99 Xô Viết Nghệ Tĩnh, Bình Thạnh, TP.HCM',
            'so_dien_thoai' => '0945678901',
            'di_ung' => [],
            'che_do_an' => [],
            'so_dia_chi' => ['99 Xô Viết Nghệ Tĩnh, Bình Thạnh, TP.HCM'],
        ]);

        // 3. Accounts (TAI_KHOAN)
        // Admin account
        $accAdmin = Account::create([
            'ten_dang_nhap' => 'admin',
            'mat_khau' => Hash::make('123'),
            'email' => 'admin@restaurant.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileAdmin->ma_nguoi_dung,
            'ma_vai_tro' => $roleAdmin->ma_vai_tro,
        ]);

        // Staff account
        $accStaff = Account::create([
            'ten_dang_nhap' => 'staff1',
            'mat_khau' => Hash::make('123'),
            'email' => 'staff1@restaurant.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileStaff->ma_nguoi_dung,
            'ma_vai_tro' => $roleStaff->ma_vai_tro,
        ]);

        // Customer 1: 'kh1' (mật khẩu mẫu '123' để test auto-upgrade hoặc login mẫu)
        $accKh1 = Account::create([
            'ten_dang_nhap' => 'kh1',
            'mat_khau' => '123', // Plaintext legacy password để test AuthService migration
            'email' => 'khachhang1@gmail.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileKh1->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        // Customer 2: 'kh2' (mật khẩu băm chuẩn BCrypt)
        $accKh2 = Account::create([
            'ten_dang_nhap' => 'kh2',
            'mat_khau' => Hash::make('Password@123'),
            'email' => 'khachhang2@gmail.com',
            'trang_thai' => AccountStatus::ACTIVE,
            'ma_nguoi_dung' => $profileKh2->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        // Locked Customer: 'kh_locked' để test kiểm tra tài khoản bị khóa
        $accKhLocked = Account::create([
            'ten_dang_nhap' => 'kh_locked',
            'mat_khau' => Hash::make('Password@123'),
            'email' => 'locked@gmail.com',
            'trang_thai' => AccountStatus::LOCKED,
            'ma_nguoi_dung' => $profileKhLocked->ma_nguoi_dung,
            'ma_vai_tro' => $roleCustomer->ma_vai_tro,
        ]);

        // 4. Carts (GIO_HANG) - 1:1 với TAI_KHOAN
        $cartKh1 = Cart::create([
            'ngay_tao' => Carbon::now(),
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
        ]);

        $cartKh2 = Cart::create([
            'ngay_tao' => Carbon::now(),
            'ma_tai_khoan' => $accKh2->ma_tai_khoan,
        ]);

        $cartKhLocked = Cart::create([
            'ngay_tao' => Carbon::now(),
            'ma_tai_khoan' => $accKhLocked->ma_tai_khoan,
        ]);

        // 5. Dining Tables (BAN_AN)
        $table1 = DiningTable::create([
            'ten_ban' => 'Bàn T01',
            'suc_chua' => 2,
            'trang_thai' => TableStatus::AVAILABLE,
            'vi_tri' => 'Tầng 1 - Cạnh cửa sổ sân vườn',
        ]);

        $table2 = DiningTable::create([
            'ten_ban' => 'Bàn T02',
            'suc_chua' => 4,
            'trang_thai' => TableStatus::AVAILABLE,
            'vi_tri' => 'Tầng 1 - Khu trung tâm',
        ]);

        $table3 = DiningTable::create([
            'ten_ban' => 'Bàn T03',
            'suc_chua' => 4,
            'trang_thai' => TableStatus::RESERVED,
            'vi_tri' => 'Tầng 1 - Khu trung tâm',
        ]);

        $table4 = DiningTable::create([
            'ten_ban' => 'Bàn T04',
            'suc_chua' => 6,
            'trang_thai' => TableStatus::AVAILABLE,
            'vi_tri' => 'Tầng 2 - Sân thượng view thoáng',
        ]);

        $tableVIP = DiningTable::create([
            'ten_ban' => 'Bàn VIP01',
            'suc_chua' => 10,
            'trang_thai' => TableStatus::CLEANING,
            'vi_tri' => 'Tầng 2 - Phòng máy lạnh riêng',
        ]);

        $tableMaint = DiningTable::create([
            'ten_ban' => 'Bàn T05',
            'suc_chua' => 4,
            'trang_thai' => TableStatus::MAINTENANCE,
            'vi_tri' => 'Tầng 1 - Khu góc vườn (Đang sửa điện)',
        ]);

        // Live Dining demo table (QR ordering), reset with `php artisan dining:demo`
        DiningTable::create([
            'ten_ban' => config('dining.demo.table_name'),
            'suc_chua' => (int) config('dining.demo.table_capacity', 4),
            'trang_thai' => TableStatus::AVAILABLE,
            'vi_tri' => config('dining.demo.table_location'),
        ]);

        // 6. Categories (DANH_MUC)
        $catKhaiVi = Category::create([
            'ten_danh_muc' => 'Khai vị',
            'mo_ta' => 'Các món ăn nhẹ kích thích vị giác trước bữa chính',
            'thu_tu_hien_thi' => 1,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $catMonChinh = Category::create([
            'ten_danh_muc' => 'Món chính',
            'mo_ta' => 'Các món ăn truyền thống và hiện đại đậm đà hương vị Việt',
            'thu_tu_hien_thi' => 2,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $catTrangMieng = Category::create([
            'ten_danh_muc' => 'Tráng miệng',
            'mo_ta' => 'Món ngọt, chè thanh nhiệt và bánh ngọt tươi',
            'thu_tu_hien_thi' => 3,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $catDoUong = Category::create([
            'ten_danh_muc' => 'Đồ uống',
            'mo_ta' => 'Nước ép, trà hoa quả tươi và cà phê thơm ngon',
            'thu_tu_hien_thi' => 4,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        // 7. Dishes (MON_AN)
        // Món Khai Vị
        $dishGoiCuon = Dish::create([
            'ten_mon_an' => 'Gỏi cuốn tôm thịt',
            'gia_tien' => 45000,
            'mo_ta' => 'Bánh tráng cuốn tôm tươi, thịt ba rọi luộc và rau thơm chấm sốt tương đậu thơm bùi',
            'hinh_anh' => '/images/menu/goi-cuon.jpg',
            'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
            'nguyen_lieu' => ['Bánh tráng', 'Tôm thẻ', 'Thịt ba rọi', 'Bún tươi', 'Rau sống'],
            'chat_di_ung' => ['SHELLFISH'],
            'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishSupCua = Dish::create([
            'ten_mon_an' => 'Súp bắp cua gà xé',
            'gia_tien' => 55000,
            'mo_ta' => 'Súp nóng béo ngọt từ thịt cua biển, ức gà xé phay, bắp Mỹ và nấm tuyết',
            'hinh_anh' => '/images/menu/sup-ga-trung.jpg',
            'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
            'nguyen_lieu' => ['Thịt cua biển', 'Ức gà', 'Bắp ngọt', 'Trứng cút', 'Nấm tuyết'],
            'chat_di_ung' => ['SHELLFISH', 'EGG'],
            'the_thoi_tiet' => ['RAINY_OR_COLD'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishSaladMe = Dish::create([
            'ten_mon_an' => 'Salad rong nho mè rang',
            'gia_tien' => 49000,
            'mo_ta' => 'Rau xanh giòn mát, cà chua bi organic kết hợp cùng sốt mè rang Nhật Bản',
            'hinh_anh' => '/images/menu/salad-rong-nho.jpg',
            'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
            'nguyen_lieu' => ['Xà lách thủy canh', 'Rong nho tươi', 'Cà chua bi', 'Sốt mè rang'],
            'chat_di_ung' => ['SESAME'],
            'the_thoi_tiet' => ['HOT_SUNNY'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        // Món Chính
        $dishPhoBo = Dish::create([
            'ten_mon_an' => 'Phở bò đặc biệt',
            'gia_tien' => 79000,
            'mo_ta' => 'Nước dùng ninh từ xương ống bò 12 tiếng cùng quế hồi thảo mộc, kèm bò tái, nạm gầu giòn',
            'hinh_anh' => '/images/menu/pho-bo.jpg',
            'ma_danh_muc' => $catMonChinh->ma_danh_muc,
            'nguyen_lieu' => ['Bánh phở tươi', 'Thịt bò tái', 'Nạm gầu', 'Bò viên', 'Nước dùng xương bò'],
            'chat_di_ung' => [],
            'the_thoi_tiet' => ['RAINY_OR_COLD', 'PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishComTam = Dish::create([
            'ten_mon_an' => 'Cơm tấm sườn bì chả trứng',
            'gia_tien' => 65000,
            'mo_ta' => 'Sườn heo ướp mật ong nướng than hoa vàng rộm, bì da heo giòn ráo, chả trứng béo ngậy',
            'hinh_anh' => '/images/menu/com-tam.jpg',
            'ma_danh_muc' => $catMonChinh->ma_danh_muc,
            'nguyen_lieu' => ['Gạo tấm thơm', 'Sườn cốt lết', 'Bì sợi', 'Chả trứng chưng', 'Mỡ hành tép mỡ'],
            'chat_di_ung' => ['EGG'],
            'the_thoi_tiet' => ['PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishBunCha = Dish::create([
            'ten_mon_an' => 'Bún chả Hà Nội nướng than hoa',
            'gia_tien' => 69000,
            'mo_ta' => 'Chả viên và chả miếng nướng đậm đà, ăn kèm bún tươi, rau kinh giới và đu đủ chua ngọt',
            'hinh_anh' => '/images/menu/bun-cha.jpg',
            'ma_danh_muc' => $catMonChinh->ma_danh_muc,
            'nguyen_lieu' => ['Bún lá', 'Thịt ba chỉ nướng', 'Chả thịt băm viên', 'Đu đủ ngâm chua', 'Nước mắm pha ấm'],
            'chat_di_ung' => [],
            'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishGoiNgoSen = Dish::create([
            'ten_mon_an' => 'Gỏi ngó sen tai heo tôm thịt (Có đậu phộng)',
            'gia_tien' => 75000,
            'mo_ta' => 'Ngó sen giòn sần sật, tôm tươi luộc ngọt nước, rắc nhiều đậu phộng rang vàng giòn',
            'hinh_anh' => '/images/menu/goi-ngo-sen.jpg',
            'ma_danh_muc' => $catMonChinh->ma_danh_muc,
            'nguyen_lieu' => ['Ngó sen', 'Tôm thẻ', 'Tai heo', 'Đậu phộng rang', 'Cà rốt', 'Rau răm'],
            'chat_di_ung' => ['PEANUT', 'SHELLFISH'], // Chứa chất dị ứng
            'the_thoi_tiet' => ['HOT_SUNNY'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        // Món Tráng Miệng
        $dishKhucBach = Dish::create([
            'ten_mon_an' => 'Chè bà ba Nam Bộ',
            'gia_tien' => 35000,
            'mo_ta' => 'Khoai lang, khoai môn và đậu xanh nấu mềm trong nước cốt dừa thơm béo theo vị chè Nam Bộ',
            'hinh_anh' => '/images/menu/che-ba-ba.jpg',
            'ma_danh_muc' => $catTrangMieng->ma_danh_muc,
            'nguyen_lieu' => ['Khoai lang', 'Khoai môn', 'Đậu xanh', 'Nước cốt dừa', 'Bột báng'],
            'chat_di_ung' => [],
            'the_thoi_tiet' => ['HOT_SUNNY'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishBanhFlan = Dish::create([
            'ten_mon_an' => 'Bánh flan caramen cà phê cốt dừa',
            'gia_tien' => 25000,
            'mo_ta' => 'Flan trứng sữa mịn mượt không rỗ bọt khí, hòa quyện caramen đăng đắng và nước cốt dừa',
            'hinh_anh' => '/images/menu/banh-flan.jpg',
            'ma_danh_muc' => $catTrangMieng->ma_danh_muc,
            'nguyen_lieu' => ['Lòng đỏ trứng', 'Sữa tươi', 'Cà phê', 'Cốt dừa'],
            'chat_di_ung' => ['EGG', 'DAIRY'],
            'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        // Đồ Uống
        $dishTraDao = Dish::create([
            'ten_mon_an' => 'Trà đào cam sả thanh mát',
            'gia_tien' => 38000,
            'mo_ta' => 'Cốt trà đen thơm đậm kết hợp cam vàng mọng nước, hương sả tự nhiên cùng những lát đào giòn ngọt',
            'hinh_anh' => '/images/menu/tra-dao.jpg',
            'ma_danh_muc' => $catDoUong->ma_danh_muc,
            'nguyen_lieu' => ['Trà đen Earl Grey', 'Đào ngâm Hy Lạp', 'Cam vàng', 'Sả cây'],
            'chat_di_ung' => [],
            'the_thoi_tiet' => ['HOT_SUNNY'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishCaPheSua = Dish::create([
            'ten_mon_an' => 'Cà phê sữa đá pha phin truyền thống',
            'gia_tien' => 29000,
            'mo_ta' => 'Hạt cà phê Robusta Buôn Ma Thuột đậm đà, sữa đặc béo ngậy đập đá nhuyễn sảng khoái',
            'hinh_anh' => '/images/menu/ca-phe-sua-da.jpg',
            'ma_danh_muc' => $catDoUong->ma_danh_muc,
            'nguyen_lieu' => ['Cà phê phin Robusta', 'Sữa đặc Ngôi Sao Phương Nam', 'Đá tinh khiết'],
            'chat_di_ung' => ['DAIRY'],
            'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        $dishTraGung = Dish::create([
            'ten_mon_an' => 'Trà gừng sả mật ong hoa rừng ấm nóng',
            'gia_tien' => 32000,
            'mo_ta' => 'Nước cốt gừng tươi già cay nồng, mật ong rừng nguyên chất làm ấm cơ thể trong ngày se lạnh hoặc mưa gió',
            'hinh_anh' => '/images/menu/tra-gung.jpg',
            'ma_danh_muc' => $catDoUong->ma_danh_muc,
            'nguyen_lieu' => ['Gừng củ già', 'Mật ong hoa rừng', 'Trà xanh Thái Nguyên', 'Chanh tươi'],
            'chat_di_ung' => [],
            'the_thoi_tiet' => ['RAINY_OR_COLD'],
            'con_ban' => true,
            'hien_thi' => true,
            'da_xoa' => false,
        ]);

        // 8. Thêm món vào Giỏ hàng mẫu của kh1 (MON_AN_GIO_HANG)
        $cartKh1->dishes()->attach([
            $dishPhoBo->ma_mon_an => ['so_luong' => 2],
            $dishTraDao->ma_mon_an => ['so_luong' => 1],
        ]);

        // 9. Orders & Order Items (DON_HANG & CHI_TIET_DON_HANG)
        // Đơn 1: Đã hoàn thành (COMPLETED) của kh1
        $order1 = Order::create([
            'ngay_dat_hang' => Carbon::now()->subDays(2),
            'tong_tien' => (79000 * 2) + 38000, // 196.000
            'trang_thai' => OrderStatus::COMPLETED,
            'dia_chi_giao_hang' => '789 Cách Mạng Tháng 8, Quận 10, TP.HCM',
            'ten_nguoi_nhan' => 'Lê Hoàng Hùng',
            'so_dien_thoai_nhan' => '0923456789',
            'loai_phuc_vu' => FulfillmentType::DELIVERY,
            'trang_thai_giao_nhan' => FulfillmentStatus::DELIVERED,
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
        ]);

        $order1->dishes()->attach([
            $dishPhoBo->ma_mon_an => ['ten_mon_snapshot' => $dishPhoBo->ten_mon_an, 'so_luong' => 2, 'don_gia' => 79000, 'trang_thai_bep' => 'SERVED'],
            $dishTraDao->ma_mon_an => ['ten_mon_snapshot' => $dishTraDao->ten_mon_an, 'so_luong' => 1, 'don_gia' => 38000, 'trang_thai_bep' => 'SERVED'],
        ]);

        Payment::create([
            'so_tien' => 196000,
            'phuong_thuc' => PaymentMethod::CASH,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order1->ma_don_hang,
        ]);
        // Thu tiền mặt khi giao (COD) — ghi vào sổ giao dịch như luồng thật.
        $this->seedReceivedMoney($order1, MoneySource::COD, PaymentMethod::CASH, null, Carbon::now()->subDays(2)->addHour());

        // Đơn 2: Chờ xác nhận (PENDING_CONFIRMATION) của kh2
        $order2 = Order::create([
            'ngay_dat_hang' => Carbon::now()->subMinutes(15),
            'tong_tien' => 65000,
            'trang_thai' => OrderStatus::PENDING_CONFIRMATION,
            'dia_chi_giao_hang' => null,
            'ten_nguoi_nhan' => 'Phạm Thị Mai',
            'so_dien_thoai_nhan' => '0934567890',
            'thoi_gian_nhan_du_kien' => Carbon::now()->addMinutes(45),
            'loai_phuc_vu' => FulfillmentType::PICKUP,
            'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
            'ma_tai_khoan' => $accKh2->ma_tai_khoan,
        ]);

        $order2->dishes()->attach([
            $dishComTam->ma_mon_an => ['ten_mon_snapshot' => $dishComTam->ten_mon_an, 'so_luong' => 1, 'don_gia' => 65000, 'trang_thai_bep' => 'SENT'],
        ]);

        Payment::create([
            'so_tien' => 65000,
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order2->ma_don_hang,
            'ma_giao_dich' => 'QR'.date('Ymd').'0002',
        ]);

        // Đơn 3: Giao hàng QR đã thanh toán, bếp đang chế biến (hiển thị trên KDS)
        $order3 = Order::create([
            'ngay_dat_hang' => Carbon::now()->subMinutes(25),
            'tong_tien' => 79000 + 45000,
            'trang_thai' => OrderStatus::PREPARING,
            'dia_chi_giao_hang' => '12 Nguyễn Thị Minh Khai, Quận 1, TP.HCM',
            'ten_nguoi_nhan' => 'Lê Hoàng Hùng',
            'so_dien_thoai_nhan' => '0923456789',
            'ghi_chu' => 'Gọi trước khi đến 5 phút',
            'loai_phuc_vu' => FulfillmentType::DELIVERY,
            'trang_thai_giao_nhan' => FulfillmentStatus::PENDING,
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
        ]);

        $order3->dishes()->attach([
            $dishPhoBo->ma_mon_an => ['ten_mon_snapshot' => $dishPhoBo->ten_mon_an, 'so_luong' => 1, 'don_gia' => 79000, 'trang_thai_bep' => KitchenItemStatus::PREPARING->value],
            $dishGoiCuon->ma_mon_an => ['ten_mon_snapshot' => $dishGoiCuon->ten_mon_an, 'so_luong' => 1, 'don_gia' => 45000, 'trang_thai_bep' => KitchenItemStatus::QUEUED->value],
        ]);

        Payment::create([
            'so_tien' => 79000 + 45000,
            'phuong_thuc' => PaymentMethod::BANK_TRANSFER_QR,
            'trang_thai' => PaymentStatus::UNPAID,
            'ma_don_hang' => $order3->ma_don_hang,
        ]);
        $this->seedReceivedMoney($order3, MoneySource::WEBHOOK, PaymentMethod::BANK_TRANSFER_QR, 'FT'.Carbon::now()->format('ymd').'88231', Carbon::now()->subMinutes(40));

        // 10. Reservations (DAT_BAN)
        // Phiếu đặt 1: kh1 giữ Bàn T03 cho tối nay (CONFIRMED) — bàn hiển thị "Đã đặt" trên sơ đồ POS
        $tonightReservationAt = Carbon::now()->addHours(3)->setMinute(0)->setSecond(0);
        if (! $tonightReservationAt->isToday()) {
            $tonightReservationAt = Carbon::tomorrow()->setTime(19, 0);
        }

        Reservation::create([
            'thoi_gian' => $tonightReservationAt,
            'so_luong_khach' => 4,
            'trang_thai' => ReservationStatus::CONFIRMED,
            'ghi_chu' => 'Tiệc kỷ niệm ngày cưới, chuẩn bị thêm nến thơm và ly rượu vang',
            'ma_tai_khoan' => $accKh1->ma_tai_khoan,
            'ma_ban' => $table3->ma_ban,
        ]);
        $table3->update([
            'trang_thai' => $tonightReservationAt->isToday() ? TableStatus::RESERVED : TableStatus::AVAILABLE,
        ]);

        // Phiên POS đang phục vụ tại Bàn T04: có món đang nấu, món đã sẵn sàng và món nháp chưa gửi bếp
        $session = ServiceSession::create([
            'ma_nhan_vien' => $accStaff->ma_tai_khoan,
            'so_luong_khach' => 3,
            'trang_thai' => PosSessionStatus::OPEN,
            'mo_luc' => Carbon::now()->subMinutes(35),
            'phien_ban' => 3,
            'idempotency_key' => 'seed-session-t04-0001',
            'request_hash' => hash('sha256', 'seed-session-t04-0001'),
        ]);
        $session->tables()->attach($table4->ma_ban);
        $table4->update(['trang_thai' => TableStatus::OCCUPIED]);

        $posOrder = Order::create([
            'ngay_dat_hang' => Carbon::now()->subMinutes(30),
            'tong_tien' => (79000 * 2) + (38000 * 3) + 45000,
            'trang_thai' => OrderStatus::PREPARING,
            'ghi_chu' => 'Khách quen, ít đá',
            'ma_tai_khoan' => $accStaff->ma_tai_khoan,
            'ma_phien' => $session->ma_phien,
            'kenh' => OrderChannel::POS,
            'loai_phuc_vu' => FulfillmentType::DINE_IN,
            'trang_thai_giao_nhan' => FulfillmentStatus::SERVING,
            'phien_ban' => 3,
        ]);

        foreach ([
            [$dishPhoBo, 2, KitchenItemStatus::PREPARING, 'Một tô không hành'],
            [$dishTraDao, 3, KitchenItemStatus::READY, null],
            [$dishGoiCuon, 1, KitchenItemStatus::DRAFT, null],
        ] as [$dish, $quantity, $kitchenStatus, $note]) {
            OrderItem::create([
                'ma_don_hang' => $posOrder->ma_don_hang,
                'ma_mon_an' => $dish->ma_mon_an,
                'ten_mon_snapshot' => $dish->ten_mon_an,
                'so_luong' => $quantity,
                'don_gia' => $dish->gia_tien,
                'ghi_chu' => $note,
                'trang_thai_bep' => $kitchenStatus,
            ]);
        }

        // Phiếu đặt 2: kh2 đặt Bàn T02 vào 2 ngày tới lúc 18:30 (PENDING)
        Reservation::create([
            'thoi_gian' => Carbon::now()->addDays(2)->setTime(18, 30),
            'so_luong_khach' => 2,
            'trang_thai' => ReservationStatus::PENDING,
            'ghi_chu' => 'Bàn gần cửa sổ, ăn chay nhẹ',
            'ma_tai_khoan' => $accKh2->ma_tai_khoan,
            'ma_ban' => $table2->ma_ban,
        ]);
    }

    /** Ghi một khoản tiền đã nhận vào sổ giao dịch và áp vào đơn (dữ liệu mẫu). */
    private function seedReceivedMoney(Order $order, MoneySource $source, PaymentMethod $method, ?string $reference, Carbon $receivedAt): void
    {
        $ledger = app(PaymentLedgerService::class);
        $recorded = $ledger->record([
            'source' => $source,
            'method' => $method,
            'amount' => (float) $order->tong_tien,
            'provider' => $method === PaymentMethod::CASH ? 'CASH_ON_DELIVERY' : null,
            'account' => $method === PaymentMethod::CASH ? null : config('services.vietqr.account_no'),
            'reference' => $reference,
            'idempotencyKey' => $reference ? null : 'seed-cod-'.$order->ma_don_hang,
            'receivedAt' => $receivedAt,
            'orderId' => $order->ma_don_hang,
            'payload' => ['source' => 'SEEDER'],
        ]);
        $ledger->applyToOrder($recorded['transaction'], $order, null, null);
    }
}
