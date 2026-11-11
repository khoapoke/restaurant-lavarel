# 🕸️ ĐẶC TẢ API MODULE: RECOMMENDATION (ĐỘNG CƠ GỢI Ý ĐỒ THỊ NEO4J)
## Phân Hệ Gợi Ý Món Ăn Bán Kèm & Cá Nhân Hóa Khẩu Vị Qua Đồ Thị Tri Thức

> **Module:** `Recommendation`  
> **Kiến trúc:** Polyglot Persistence (MySQL + Neo4j Graph Database + Redis)  
> **Thư viện Backend:** `laudis/neo4j-php-client` (Bolt Protocol `bolt://localhost:7687`)  
> **Quy chuẩn Response:** Tuân thủ 100% Envelope chuẩn tại [`README.md`](README.md).

---

## 📌 BẢNG TỔNG HỢP ENDPOINTS (RECOMMENDATION APIS)

| Mã API | Method | Endpoint URL | Phân quyền | Tên tính năng & Mô tả |
| :---: | :---: | :--- | :--- | :--- |
| **`GRA-01`** | `GET` | `/api/v1/customer/dishes/{dishId}/recommendations/graph` | Public | **Gợi ý món ăn bán kèm (Cross-sell / Market Basket Analysis)** dựa trên các đơn hàng cùng đặt món này trong quá khứ trên đồ thị Neo4j. |
| **`GRA-02`** | `GET` | `/api/v1/customer/recommendations/personalized` | `CUSTOMER` | **Gợi ý món ăn cá nhân hóa (Collaborative Filtering)** dựa trên khách hàng có lịch sử và khẩu vị tương đồng trên đồ thị. |
| **`GRA-03`** | `GET` | `/api/v1/customer/recommendations/graph-stats` | Public | **Thống kê đồ thị tri thức**: Tổng số Node và Relationship trong CSDL Neo4j theo thời gian thực. |

---

## 🧠 MÔ HÌNH ĐỒ THỊ TRI THỨC (GRAPH SCHEMA)

```mermaid
graph LR
    C["(:Customer)"] -->|ORDERED| O["(:Order)"]
    O -->|INCLUDES {quantity, unitPrice}| D1["(:Dish)"]
    O -->|INCLUDES {quantity, unitPrice}| D2["(:Dish)"]
    D1 -->|BELONGS_TO| CAT["(:Category)"]
    D2 -->|BELONGS_TO| CAT
    D1 -.->|FREQUENTLY_PAIRED_WITH| D2
```

- **Nodes:**
  - `(:Customer {customerId, username, fullName})`
  - `(:Dish {dishId, name, price, imageUrl, categoryName})`
  - `(:Category {categoryId, name})`
  - `(:Order {orderId, totalAmount, orderDate})`
- **Relationships:**
  - `(:Customer)-[:ORDERED]->(:Order)`
  - `(:Order)-[:INCLUDES {quantity, unitPrice}]->(:Dish)`
  - `(:Dish)-[:BELONGS_TO]->(:Category)`

---

## 1. CHI TIẾT CÁC ENDPOINT API

### 1.1. Gợi ý món ăn bán kèm theo món đang xem (`GRA-01`)
Áp dụng thuật toán **Market Basket Analysis** để tìm các món thường xuyên xuất hiện cùng món ăn mục tiêu trong các đơn hàng đã thanh toán.

- **Method & URL:** `GET /api/v1/customer/dishes/{dishId}/recommendations/graph`
- **Query Params:**
  - `limit` (`int`, optional, mặc định: `4`, tối đa: `20`): Số lượng món gợi ý tối đa cần lấy.
- **Truy vấn Cypher nền tảng:**
  ```cypher
  MATCH (target:Dish {dishId: $dishId})<-[:INCLUDES]-(o:Order)-[:INCLUDES]->(rec:Dish)
  WHERE rec.dishId <> $dishId
  RETURN rec.dishId AS dishId,
         rec.name AS dishName,
         rec.price AS price,
         rec.imageUrl AS imageUrl,
         rec.categoryName AS categoryName,
         count(o) AS pairedCount
  ORDER BY pairedCount DESC, rec.dishId ASC
  LIMIT $limit
  ```
- **Cơ chế Graceful Fallback:** Nếu đồ thị mới tạo hoặc món ăn chưa có nhiều đơn hàng kết hợp, hệ thống tự động bù đắp các món nổi bật cùng danh mục từ danh mục thực đơn MySQL (`"source": "catalog_supplement"`).
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Graph recommendations retrieved successfully",
  "data": [
    {
      "dishId": 2,
      "dishName": "Súp bắp cua gà xé",
      "price": 55000,
      "imageUrl": "https://example.com/images/sup-cua.jpg",
      "categoryName": "Khai vị",
      "pairedCount": 3,
      "source": "neo4j_graph"
    },
    {
      "dishId": 12,
      "dishName": "Trà đào cam sả",
      "price": 39000,
      "imageUrl": "https://example.com/images/tra-dao.jpg",
      "categoryName": "Đồ uống",
      "pairedCount": 2,
      "source": "neo4j_graph"
    },
    {
      "dishId": 3,
      "dishName": "Salad rong nho mè rang",
      "price": 49000,
      "imageUrl": "https://example.com/images/salad.jpg",
      "categoryName": "Khai vị",
      "pairedCount": 0,
      "source": "catalog_supplement"
    }
  ]
}
```

---

### 1.2. Gợi ý món ăn cá nhân hóa theo khẩu vị (`GRA-02`)
Áp dụng thuật toán **Lọc cộng tác (Collaborative Filtering)**: Tìm những khách hàng có lịch sử đặt món tương đồng nhất với khách hiện tại, sau đó đề xuất các món mà những khách hàng tương đồng đó từng gọi nhưng khách hiện tại chưa từng thử.

- **Method & URL:** `GET /api/v1/customer/recommendations/personalized`
- **Middleware:** `auth:sanctum`, `account.active`, `role:CUSTOMER`
- **Headers:** `Authorization: Bearer <customer_token>`
- **Query Params:**
  - `limit` (`int`, optional, mặc định: `5`, tối đa: `20`).
- **Truy vấn Cypher nền tảng:**
  ```cypher
  MATCH (me:Customer {customerId: $customerId})-[:ORDERED]->(:Order)-[:INCLUDES]->(d:Dish)
  MATCH (other:Customer)-[:ORDERED]->(:Order)-[:INCLUDES]->(d)
  WHERE other.customerId <> me.customerId
  MATCH (other)-[:ORDERED]->(:Order)-[:INCLUDES]->(rec:Dish)
  WHERE NOT (me)-[:ORDERED]->(:Order)-[:INCLUDES]->(rec)
  RETURN rec.dishId AS dishId,
         rec.name AS dishName,
         rec.price AS price,
         rec.imageUrl AS imageUrl,
         rec.categoryName AS categoryName,
         count(DISTINCT other) AS affinityScore
  ORDER BY affinityScore DESC, rec.dishId ASC
  LIMIT $limit
  ```
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Personalized recommendations generated successfully",
  "data": [
    {
      "dishId": 6,
      "dishName": "Lẩu Thái hải sản chua cay",
      "price": 289000,
      "imageUrl": "https://example.com/images/lau-thai.jpg",
      "categoryName": "Món lẩu",
      "affinityScore": 4,
      "source": "neo4j_collaborative"
    },
    {
      "dishId": 14,
      "dishName": "Bánh flan trân châu đường đen",
      "price": 25000,
      "imageUrl": "https://example.com/images/flan.jpg",
      "categoryName": "Tráng miệng",
      "affinityScore": 2,
      "source": "neo4j_collaborative"
    }
  ]
}
```

---

### 1.3. Thống kê dữ liệu Đồ thị Neo4j (`GRA-03`)
Cung cấp tổng quan về quy mô mạng lưới đồ thị để phục vụ việc giám sát, trực quan hóa và kiểm thử.

- **Method & URL:** `GET /api/v1/customer/recommendations/graph-stats`
- **Phân quyền:** Public
- **Response JSON Sample (Success - 200 OK):**
```json
{
  "success": true,
  "code": 200,
  "message": "Graph database statistics retrieved successfully",
  "data": {
    "customers": 7,
    "dishes": 14,
    "categories": 6,
    "orders": 38,
    "includesRelationships": 39,
    "orderedRelationships": 38
  }
}
```

---

## 2. CƠ CHẾ ĐỒNG BỘ DỮ LIỆU GRAPH (SYNCHRONIZATION)

1. **Đồng bộ hàng loạt ban đầu (Batch Sync):**
   - Chạy lệnh Artisan Command:
     ```bash
     php artisan neo4j:sync --fresh
     ```
   - Tự động nạp toàn bộ dữ liệu hiện có từ MySQL vào Neo4j và tạo các chỉ mục duy nhất (`UNIQUE CONSTRAINT`).
2. **Đồng bộ tự động thời gian thực (Real-time Event Hook):**
   - Khi khách hàng hoàn tất đặt đơn tại `OrderService@checkout`, hệ thống tự động ghi nhận ngay lập tức nút `:Order` và các liên kết `[:INCLUDES]` sang đồ thị Neo4j.
   - Thao tác này chạy với cơ chế bắt lỗi an toàn (Non-blocking / Graceful degradation), không làm gián đoạn luồng thanh toán MySQL nếu Neo4j tạm thời bảo trì.

