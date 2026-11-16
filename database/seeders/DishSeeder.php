<?php

namespace Database\Seeders;

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Dish;
use Illuminate\Database\Seeder;

class DishSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Bảng MON_AN (Tier 1 - Phụ thuộc vào DANH_MUC).
     */
    public function run(): void
    {
        // Tra cứu Categories
        $catKhaiVi = Category::where('ten_danh_muc', 'Khai vị')->firstOrFail();
        $catMonChinh = Category::where('ten_danh_muc', 'Món chính')->firstOrFail();
        $catTrangMieng = Category::where('ten_danh_muc', 'Tráng miệng')->firstOrFail();
        $catDoUong = Category::where('ten_danh_muc', 'Đồ uống')->firstOrFail();

        $dishes = [
            // Khai vị
            [
                'ten_mon_an' => 'Gỏi cuốn tôm thịt',
                'gia_tien' => 45000,
                'mo_ta' => 'Bánh tráng cuốn tôm tươi, thịt ba rọi luộc và rau thơm chấm sốt tương đậu thơm bùi',
                'hinh_anh' => 'https://images.unsplash.com/photo-1548946526-f69e2424cf45?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
                'nguyen_lieu' => ['Bánh tráng', 'Tôm thẻ', 'Thịt ba rọi', 'Bún tươi', 'Rau sống'],
                'chat_di_ung' => ['SHELLFISH'],
                'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Súp bắp cua gà xé',
                'gia_tien' => 55000,
                'mo_ta' => 'Súp nóng béo ngọt từ thịt cua biển, ức gà xé phay, bắp Mỹ và nấm tuyết',
                'hinh_anh' => 'https://images.unsplash.com/photo-1547592166-23ac45744acd?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
                'nguyen_lieu' => ['Thịt cua biển', 'Ức gà', 'Bắp ngọt', 'Trứng cút', 'Nấm tuyết'],
                'chat_di_ung' => ['SHELLFISH', 'EGG'],
                'the_thoi_tiet' => ['RAINY_OR_COLD'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Salad rong nho mè rang',
                'gia_tien' => 49000,
                'mo_ta' => 'Rau xanh giòn mát, cà chua bi organic kết hợp cùng sốt mè rang Nhật Bản',
                'hinh_anh' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catKhaiVi->ma_danh_muc,
                'nguyen_lieu' => ['Xà lách thủy canh', 'Rong nho tươi', 'Cà chua bi', 'Sốt mè rang'],
                'chat_di_ung' => ['SESAME'],
                'the_thoi_tiet' => ['HOT_SUNNY'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],

            // Món chính
            [
                'ten_mon_an' => 'Phở bò đặc biệt',
                'gia_tien' => 79000,
                'mo_ta' => 'Nước dùng ninh từ xương ống bò 12 tiếng cùng quế hồi thảo mộc, kèm bò tái, nạm gầu giòn',
                'hinh_anh' => 'https://images.unsplash.com/photo-1582878826629-29b7ad1cdc43?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catMonChinh->ma_danh_muc,
                'nguyen_lieu' => ['Bánh phở tươi', 'Thịt bò tái', 'Nạm gầu', 'Bò viên', 'Nước dùng xương bò'],
                'chat_di_ung' => [],
                'the_thoi_tiet' => ['RAINY_OR_COLD', 'PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Cơm tấm sườn bì chả trứng',
                'gia_tien' => 65000,
                'mo_ta' => 'Sườn heo ướp mật ong nướng than hoa vàng rộm, bì da heo giòn ráo, chả trứng béo ngậy',
                'hinh_anh' => 'https://images.unsplash.com/photo-1540420773420-3366772f4999?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catMonChinh->ma_danh_muc,
                'nguyen_lieu' => ['Gạo tấm thơm', 'Sườn cốt lết', 'Bì sợi', 'Chả trứng chưng', 'Mỡ hành tép mỡ'],
                'chat_di_ung' => ['EGG'],
                'the_thoi_tiet' => ['PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Bún chả Hà Nội nướng than hoa',
                'gia_tien' => 69000,
                'mo_ta' => 'Chả viên và chả miếng nướng đậm đà, ăn kèm bún tươi, rau kinh giới và đu đủ chua ngọt',
                'hinh_anh' => 'https://images.unsplash.com/photo-1569058242253-92a9c755a0ec?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catMonChinh->ma_danh_muc,
                'nguyen_lieu' => ['Bún lá', 'Thịt ba chỉ nướng', 'Chả thịt băm viên', 'Đu đủ ngâm chua', 'Nước mắm pha ấm'],
                'chat_di_ung' => [],
                'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Gỏi ngó sen tai heo tôm thịt (Có đậu phộng)',
                'gia_tien' => 75000,
                'mo_ta' => 'Ngó sen giòn sần sật, tôm tươi luộc ngọt nước, rắc nhiều đậu phộng rang vàng giòn',
                'hinh_anh' => 'https://images.unsplash.com/photo-1559847844-5315695dadae?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catMonChinh->ma_danh_muc,
                'nguyen_lieu' => ['Ngó sen', 'Tôm thẻ', 'Tai heo', 'Đậu phộng rang', 'Cà rốt', 'Rau răm'],
                'chat_di_ung' => ['PEANUT', 'SHELLFISH'], // Chứa chất dị ứng
                'the_thoi_tiet' => ['HOT_SUNNY'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],

            // Tráng miệng
            [
                'ten_mon_an' => 'Chè khúc bạch long nhãn hạnh nhân',
                'gia_tien' => 35000,
                'mo_ta' => 'Khúc bạch phô mai sữa béo ngậy, long nhãn mọng nước, hạnh nhân rang thơm lừng trong nước đường thanh mát',
                'hinh_anh' => 'https://images.unsplash.com/photo-1551024709-8f23befc6f87?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catTrangMieng->ma_danh_muc,
                'nguyen_lieu' => ['Khúc bạch sữa kem', 'Long nhãn', 'Hạnh nhân lát', 'Nước đường phèn'],
                'chat_di_ung' => ['DAIRY', 'TREE_NUTS'],
                'the_thoi_tiet' => ['HOT_SUNNY'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Bánh flan caramen cà phê cốt dừa',
                'gia_tien' => 25000,
                'mo_ta' => 'Flan trứng sữa mịn mượt không rỗ bọt khí, hòa quyện caramen đăng đắng và nước cốt dừa',
                'hinh_anh' => 'https://images.unsplash.com/photo-1528975604071-b4dc52a2d18c?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catTrangMieng->ma_danh_muc,
                'nguyen_lieu' => ['Lòng đỏ trứng', 'Sữa tươi', 'Cà phê', 'Cốt dừa'],
                'chat_di_ung' => ['EGG', 'DAIRY'],
                'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],

            // Đồ uống
            [
                'ten_mon_an' => 'Trà đào cam sả thanh mát',
                'gia_tien' => 38000,
                'mo_ta' => 'Cốt trà đen thơm đậm kết hợp cam vàng mọng nước, hương sả tự nhiên cùng những lát đào giòn ngọt',
                'hinh_anh' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catDoUong->ma_danh_muc,
                'nguyen_lieu' => ['Trà đen Earl Grey', 'Đào ngâm Hy Lạp', 'Cam vàng', 'Sả cây'],
                'chat_di_ung' => [],
                'the_thoi_tiet' => ['HOT_SUNNY'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Cà phê sữa đá pha phin truyền thống',
                'gia_tien' => 29000,
                'mo_ta' => 'Hạt cà phê Robusta Buôn Ma Thuột đậm đà, sữa đặc béo ngậy đập đá nhuyễn sảng khoái',
                'hinh_anh' => 'https://images.unsplash.com/photo-1517256064527-09c73fc73e38?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catDoUong->ma_danh_muc,
                'nguyen_lieu' => ['Cà phê phin Robusta', 'Sữa đặc Ngôi Sao Phương Nam', 'Đá tinh khiết'],
                'chat_di_ung' => ['DAIRY'],
                'the_thoi_tiet' => ['HOT_SUNNY', 'PLEASANT_NORMAL'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
            [
                'ten_mon_an' => 'Trà gừng sả mật ong hoa rừng ấm nóng',
                'gia_tien' => 32000,
                'mo_ta' => 'Nước cốt gừng tươi già cay nồng, mật ong rừng nguyên chất làm ấm cơ thể trong ngày se lạnh hoặc mưa gió',
                'hinh_anh' => 'https://images.unsplash.com/photo-1576092768241-dec231879fc3?auto=format&fit=crop&w=600&q=80',
                'ma_danh_muc' => $catDoUong->ma_danh_muc,
                'nguyen_lieu' => ['Gừng củ già', 'Mật ong hoa rừng', 'Trà xanh Thái Nguyên', 'Chanh tươi'],
                'chat_di_ung' => [],
                'the_thoi_tiet' => ['RAINY_OR_COLD'],
                'con_ban' => true,
                'hien_thi' => true,
                'da_xoa' => false,
            ],
        ];

        foreach ($dishes as $dish) {
            Dish::firstOrCreate(
                ['ten_mon_an' => $dish['ten_mon_an']],
                $dish
            );
        }
    }
}

