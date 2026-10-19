<?php

namespace App\Services\Customer;

use App\Models\Category;
use App\Models\Dish;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MenuService
{
    /**
     * Get active and visible categories list (MNU-01).
     *
     * @return Collection
     */
    public function getCategories(): Collection
    {
        return Category::where('da_xoa', false)
            ->where('hien_thi', true)
            ->orderBy('thu_tu_hien_thi', 'asc')
            ->orderBy('ma_danh_muc', 'asc')
            ->get();
    }

    /**
     * Get paginated dishes list with filters (MNU-02, MNU-03).
     *
     * @param array $filters
     * @return LengthAwarePaginator
     * @throws HttpException
     */
    public function getDishes(array $filters = []): LengthAwarePaginator
    {
        // Kiểm tra hợp lệ khoảng giá: minPrice không được lớn hơn maxPrice
        $hasMinPrice = isset($filters['minPrice']) && is_numeric($filters['minPrice']);
        $hasMaxPrice = isset($filters['maxPrice']) && is_numeric($filters['maxPrice']);

        if ($hasMinPrice && $hasMaxPrice && (float) $filters['minPrice'] > (float) $filters['maxPrice']) {
            throw new HttpException(400, 'minPrice cannot be greater than maxPrice');
        }

        $query = Dish::with('category')
            ->where('da_xoa', false)
            ->where('hien_thi', true);

        // 1. Tìm kiếm theo keyword trong tên món ăn (ten_mon_an)
        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where('ten_mon_an', 'like', "%{$keyword}%");
        }

        // 2. Lọc theo danh mục (categoryId)
        if (!empty($filters['categoryId']) && is_numeric($filters['categoryId'])) {
            $query->where('ma_danh_muc', (int) $filters['categoryId']);
        }

        // 3. Lọc theo khoảng giá (minPrice, maxPrice)
        if ($hasMinPrice) {
            $query->where('gia_tien', '>=', (float) $filters['minPrice']);
        }
        if ($hasMaxPrice) {
            $query->where('gia_tien', '<=', (float) $filters['maxPrice']);
        }

        // 4. Lọc theo tình trạng còn bán (isAvailable)
        if (isset($filters['isAvailable'])) {
            $isAvailable = filter_var($filters['isAvailable'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isAvailable !== null) {
                $query->where('con_ban', $isAvailable);
            }
        }

        // 5. Loại bỏ món chứa chất dị ứng (excludeAllergens: danh sách cách nhau bởi dấu phẩy)
        if (!empty($filters['excludeAllergens'])) {
            $allergensList = explode(',', $filters['excludeAllergens']);
            foreach ($allergensList as $allergen) {
                $allergen = strtoupper(trim($allergen));
                if (!empty($allergen)) {
                    $query->where(function (Builder $q) use ($allergen) {
                        $q->whereNull('chat_di_ung')
                            ->orWhereJsonDoesntContain('chat_di_ung', $allergen);
                    });
                }
            }
        }

        $perPage = (int) ($filters['perPage'] ?? 12);
        $page = (int) ($filters['page'] ?? 1);

        return $query->orderBy('ma_mon_an', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get dish detail by ID (MNU-07).
     *
     * @param int $dishId
     * @return Dish
     * @throws HttpException
     */
    public function getDishDetail(int $dishId): Dish
    {
        $dish = Dish::with('category')
            ->where('da_xoa', false)
            ->where('hien_thi', true)
            ->find($dishId);

        if (!$dish) {
            throw new HttpException(404, 'Dish not found or unavailable');
        }

        return $dish;
    }

    /**
     * Get weather-based dish recommendations and auto-exclude user allergens (MNU-04).
     *
     * @param float $lat
     * @param float $lon
     * @param \App\Models\Account|null $account
     * @return array
     */
    public function getWeatherRecommendations(float $lat, float $lon, ?\App\Models\Account $account = null): array
    {
        $roundedLat = round($lat, 2);
        $roundedLon = round($lon, 2);
        $accountId = $account?->ma_tai_khoan ?? 'guest';
        $cacheKey = "weather_rec_{$roundedLat}_{$roundedLon}_{$accountId}";

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 900, function () use ($lat, $lon, $account) {
            // 1. Lấy thông tin thời tiết (qua OpenWeatherMap hoặc fallback)
            $weatherData = $this->fetchWeatherData($lat, $lon);

            $temp = (float) ($weatherData['temp'] ?? 33.8);
            $city = $weatherData['city'] ?? 'Ho Chi Minh City';

            // 2. Xác định điều kiện thời tiết
            if ($temp >= 32) {
                $condition = 'HOT_SUNNY';
                $baseAdvice = "Trời nắng nóng ({$temp}°C), ưu tiên các món thanh mát.";
            } elseif ($temp <= 22) {
                $condition = 'RAINY_OR_COLD';
                $baseAdvice = "Trời mưa hoặc lạnh ({$temp}°C), ưu tiên các món cay nóng, lẩu và súp ấm bụng.";
            } else {
                $condition = 'PLEASANT_NORMAL';
                $baseAdvice = "Thời tiết dễ chịu ({$temp}°C), phù hợp với mọi món ăn đặc sắc của nhà hàng.";
            }

            // 3. Lấy danh sách dị ứng của khách hàng nếu đã đăng nhập
            $excludedAllergens = [];
            if ($account) {
                if (!$account->relationLoaded('userProfile')) {
                    $account->load('userProfile');
                }
                $allergies = $account->userProfile?->allergies ?? [];
                if (is_array($allergies)) {
                    $excludedAllergens = array_values(array_filter($allergies));
                }
            }

            $advice = $baseAdvice;
            if (!empty($excludedAllergens)) {
                $advice .= " Đã lọc bỏ món chứa chất gây dị ứng: " . implode(', ', $excludedAllergens) . ".";
            }

            // 4. Lọc các món ăn phù hợp với thời tiết và không chứa chất dị ứng
            $query = Dish::with('category')
                ->where('da_xoa', false)
                ->where('hien_thi', true)
                ->where('con_ban', true);

            // Khớp tag thời tiết
            $query->where(function (Builder $q) use ($condition) {
                $q->whereJsonContains('the_thoi_tiet', $condition)
                    ->orWhereNull('the_thoi_tiet');
            });

            // Loại bỏ món chứa dị ứng của người dùng
            foreach ($excludedAllergens as $allergen) {
                $allergen = strtoupper(trim($allergen));
                if (!empty($allergen)) {
                    $query->where(function (Builder $q) use ($allergen) {
                        $q->whereNull('chat_di_ung')
                            ->orWhereJsonDoesntContain('chat_di_ung', $allergen);
                    });
                }
            }

            $dishes = $query->limit(6)->get();

            $recommendedDishes = $dishes->map(function (Dish $dish) {
                return [
                    'dishId' => (int) $dish->dishId,
                    'dishName' => $dish->dishName,
                    'price' => (float) $dish->price,
                    'imageUrl' => $dish->imageUrl,
                    'categoryName' => $dish->category?->categoryName ?? '',
                    'allergens' => $dish->allergens ?? [],
                ];
            })->values()->all();

            return [
                'weather' => [
                    'city' => $city,
                    'temperatureCelsius' => $temp,
                    'condition' => $condition,
                    'advice' => $advice,
                ],
                'excludedAllergens' => $excludedAllergens,
                'recommendedDishes' => $recommendedDishes,
            ];
        });
    }

    /**
     * Fetch weather data from external API or intelligent fallback.
     */
    protected function fetchWeatherData(float $lat, float $lon): array
    {
        $apiKey = config('services.openweather.key') ?? env('OPENWEATHER_API_KEY');

        if ($apiKey) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(3)->get('https://api.openweathermap.org/data/2.5/weather', [
                    'lat' => $lat,
                    'lon' => $lon,
                    'appid' => $apiKey,
                    'units' => 'metric',
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    return [
                        'city' => $json['name'] ?? 'Local City',
                        'temp' => round((float) ($json['main']['temp'] ?? 30.0), 1),
                    ];
                }
            } catch (\Throwable $e) {
                // Fallback on timeout or API error
            }
        }

        // Tọa độ gần TP.HCM (lat ~ 10.x, lon ~ 106.x)
        if (abs($lat - 10.7) < 2.0 && abs($lon - 106.6) < 2.0) {
            return ['city' => 'Ho Chi Minh City', 'temp' => 33.8];
        }
        // Tọa độ gần Hà Nội (lat ~ 21.x, lon ~ 105.x)
        if (abs($lat - 21.0) < 2.0 && abs($lon - 105.8) < 2.0) {
            return ['city' => 'Hanoi', 'temp' => 21.5];
        }
        // Tọa độ gần Đà Nẵng (lat ~ 16.x, lon ~ 108.x)
        if (abs($lat - 16.0) < 2.0 && abs($lon - 108.2) < 2.0) {
            return ['city' => 'Da Nang', 'temp' => 28.0];
        }

        return ['city' => 'Ho Chi Minh City', 'temp' => 33.8];
    }
}

