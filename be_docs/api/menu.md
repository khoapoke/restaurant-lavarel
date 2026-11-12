# 🥗 ĐẶC TẢ API MODULE: MENU (THỰC ĐƠN, DANH MỤC & MÓN ĂN)

> **Namespace dự kiến:** `App\Modules\Menu\`  
> **Controllers:** `Customer\MenuController`, `Admin\CategoryController`, `Admin\DishController`  
> **Models:** `Category` (`DANH_MUC`), `Dish` (`MON_AN`)  
> **Redis Cache:** `menu:categories:active` (3600s), `weather_rec_*` (900s)

---

## 1. CUSTOMER MENU APIS

### 1.1. Danh mục thực đơn (`MNU-01`)
- **Method & URL:** `GET /api/v1/customer/categories`
- **Cache Store:** Redis (`menu:categories:active`, 3600s)
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Categories retrieved successfully",
  "data": [
    {
      "categoryId": 1,
      "categoryName": "Khai vị",
      "description": "Các món ăn nhẹ kích thích vị giác trước bữa chính",
      "displayOrder": 1
    },
    {
      "categoryId": 2,
      "categoryName": "Món chính",
      "description": "Các món ăn truyền thống và hiện đại đậm đà hương vị Việt",
      "displayOrder": 2
    }
  ]
}
```

---

### 1.2. Danh sách món ăn có bộ lọc & phân trang (`MNU-02`, `MNU-03`)
- **Method & URL:** `GET /api/v1/customer/dishes`
- **Công nghệ tìm kiếm (Smart Search Engine):** **Meilisearch** (qua Laravel Scout) kết hợp MySQL:
  - **Typo Tolerance:** Hỗ trợ tìm kiếm chịu lỗi chính tả (ví dụ: gõ `tra daoo` vẫn tìm thấy `Trà đào cam sả`, gõ `phoo bo` vẫn ra `Phở bò đặc biệt`).
  - **Unaccented Search:** Hỗ trợ gõ tiếng Việt không dấu chuẩn xác (`com tam`, `che khuc bach`, `tra dao`).
  - **Relevance Ranking:** Sắp xếp các kết quả có độ tương đồng cao nhất lên đầu.
  - **Graceful Fallback:** Tự động fallback về truy vấn SQL `LIKE` an toàn nếu dịch vụ Meilisearch tạm thời gián đoạn.
- **Query Params:**
  - `keyword` (`string`, optional): Từ khóa tìm kiếm thông minh full-text (tên món ăn, mô tả, danh mục).
  - `categoryId` (`int`, optional): Lọc theo danh mục.
  - `minPrice`, `maxPrice` (`numeric`, optional): Khoảng giá.
  - `isAvailable` (`boolean`, optional): Tình trạng còn bán (`true`/`false`).
  - `excludeAllergens` (`string`, optional): Loại trừ chất dị ứng (ví dụ: `SHELLFISH,PEANUT`).
  - `page` (`int`, default `1`), `perPage` (`int`, default `12`).
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dishes retrieved successfully",
  "data": {
    "items": [
      {
        "dishId": 2,
        "dishName": "Bò sốt tiêu đen",
        "price": 350000.00,
        "description": "Thịt bò Mỹ mềm ngon kèm sốt tiêu đen đậm đà",
        "imageUrl": "bo_tieu_den.jpg",
        "categoryId": 2,
        "categoryName": "Món chính",
        "isAvailable": true,
        "ingredients": ["Thịt bò Mỹ", "Tiêu đen", "Bơ lạt"],
        "allergens": ["DAIRY"]
      }
    ],
    "pagination": {
      "currentPage": 1,
      "perPage": 12,
      "totalItems": 1,
      "lastPage": 1
    }
  }
}
```
- **Response JSON Sample (Error - 400 Bad Request):**
```json
{
  "success": false,
  "code": 400,
  "message": "minPrice cannot be greater than maxPrice",
  "errors": null
}
```

---

### 1.3. Chi tiết món ăn (`MNU-07`)
- **Method & URL:** `GET /api/v1/customer/dishes/{dishId}`
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Dish detail fetched successfully",
  "data": {
    "dishId": 1,
    "dishName": "Súp bào ngư",
    "price": 150000.00,
    "description": "Súp bào ngư thượng hạng giàu dinh dưỡng",
    "imageUrl": "sup_bao_ngu.jpg",
    "category": {
      "categoryId": 1,
      "categoryName": "Khai vị"
    },
    "isAvailable": true,
    "ingredients": ["Bào ngư tươi", "Nấm đông cô", "Trứng gà"],
    "allergens": ["SHELLFISH", "EGG"]
  }
}
```
- **Response JSON Sample (Error - 404 Not Found):**
```json
{
  "success": false,
  "code": 404,
  "message": "Dish not found or unavailable",
  "errors": null
}
```

---

### 1.4. Gợi ý món ăn theo thời tiết thực tế (`MNU-04`)
- **Method & URL:** `GET /api/v1/customer/recommendations/weather`
- **Query Params:** `lat` (`numeric`, required), `lon` (`numeric`, required).
- **Cache Store:** Redis (`weather_rec_{lat}_{lon}_{accountId}`, 900s)
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Weather recommendations generated successfully",
  "data": {
    "weather": {
      "city": "Ho Chi Minh City",
      "temperatureCelsius": 33.8,
      "condition": "HOT_SUNNY",
      "advice": "Trời nắng nóng (33.8°C), ưu tiên các món thanh mát. Đã lọc bỏ món chứa chất gây dị ứng: SHELLFISH, PEANUT."
    },
    "excludedAllergens": ["SHELLFISH", "PEANUT"],
    "recommendedDishes": [
      {
        "dishId": 4,
        "dishName": "Trà đào cam sả",
        "price": 45000.00,
        "imageUrl": "tra_dao.jpg",
        "categoryName": "Đồ uống",
        "allergens": []
      }
    ]
  }
}
```

---

## 2. ADMIN CATEGORY & DISH MANAGEMENT APIS

### 2.1. Thêm mới danh mục món ăn (`MNU-09`)
- **Method & URL:** `POST /api/v1/admin/categories`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Request Body:**
```json
{
  "categoryName": "Lẩu & Nướng",
  "description": "Các món lẩu nghi ngút khói và nướng xèo xèo",
  "displayOrder": 5,
  "isVisible": true
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Category created successfully",
  "data": {
    "categoryId": 5,
    "categoryName": "Lẩu & Nướng",
    "displayOrder": 5,
    "isVisible": true
  }
}
```
- **Ghi chú:** Tự động gọi `Cache::forget('menu:categories:active')`.

---

### 2.2. Thêm mới món ăn (`MNU-13`)
- **Method & URL:** `POST /api/v1/admin/dishes`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Request Body:**
```json
{
  "dishName": "Gà nướng Tây Bắc",
  "price": 280000.00,
  "description": "Gà đi bộ nướng mật ong và mắc khén",
  "imageUrl": "ga_nuong.jpg",
  "categoryId": 2,
  "ingredients": ["Gà ta", "Mật ong", "Mắc khén"],
  "allergens": [],
  "weatherTags": ["RAINY_OR_COLD", "PLEASANT_NORMAL"],
  "isAvailable": true,
  "isVisible": true
}
```
- **Response JSON Sample (Success - 201 Created):**
```json
{
  "success": true,
  "code": 201,
  "message": "Dish created successfully",
  "data": {
    "dishId": 3,
    "dishName": "Gà nướng Tây Bắc",
    "price": 280000.00,
    "categoryId": 2,
    "isAvailable": true,
    "isVisible": true,
    "isDeleted": false
  }
}
```

---

### 2.3. Xóa mềm món ăn (`MNU-16`)
- **Method & URL:** `DELETE /api/v1/admin/dishes/{dishId}`
- **Middleware:** `auth:sanctum`, `account.active`, `role:ADMIN`
- **Ghi chú:** Tuyệt đối không xóa vật lý (`DELETE FROM MON_AN`). Cập nhật `da_xoa = true` và `hien_thi = false` để giữ toàn vẹn ràng buộc với `CHI_TIET_DON_HANG`.

