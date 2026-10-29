# Web API Service: Create Order Documentation

## Overview

The Web Orders API service enables external client applications (e.g., WordPress/WooCommerce, Next.js storefront, mobile app) to submit customer orders directly into the **Larament** restaurant POS system.

When an order is placed through this endpoint, the system automatically:
1. Validates the incoming payload against POS rules and checks that the current shift is active.
2. Creates or updates the customer record using their phone number as a unique key.
3. Maps web menu items and bundled items (`posRefObj`) to internal POS products via `product_ref`.
4. Calculates product costs, subtotals, POS discrepancies (`web_pos_diff`), discounts, and net profits.
5. Emits the `WebOrderReceived` broadcast event (via Laravel Reverb WebSockets) to alert kitchen staff and cashiers in real time.

---

## Service Endpoints & Flow

### 1. Verification Flow

Before initiating an order placement request, the client website should check whether the restaurant can receive orders and obtain the active shift ID:

```
[Client Website]
       |
       +---> GET /api/can-accept-order   ---> Check restaurant status (true/false)
       |
       +---> GET /api/get-shift-id       ---> Retrieve active shift ID
       |
       +---> POST /api/web-orders/place-order ---> Submit full order payload
```

---

## Endpoint Specifications

### 1. Check Store Availability

Determine if the restaurant is currently accepting online orders (active shift exists or daily activity is recorded).

- **Method**: `GET`
- **URL**: `/api/can-accept-order`
- **Authentication**: None
- **Headers**:
  - `Accept: application/json`

#### Response (`200 OK`)
```json
{
  "can_accept": true
}
```

---

### 2. Get Active Shift ID

Retrieve the unique identifier of the currently open POS shift. This ID is **required** when placing an order.

- **Method**: `GET`
- **URL**: `/api/get-shift-id`
- **Authentication**: None
- **Headers**:
  - `Accept: application/json`

#### Response (`200 OK`)
```json
{
  "shift_id": 42
}
```
> **Note**: If no shift is open, `shift_id` will be `null`. Placing an order without an active shift will result in an error (`400 Bad Request`).

---

### 3. Place Web Order

Submit a new web delivery or takeaway order to the POS.

- **Method**: `POST`
- **URL**: `/api/web-orders/place-order`
- **Controller Action**: `App\Http\Controllers\Api\WebOrdersController::createOrder`
- **Service Handler**: `App\Services\WebApiService::placeOrder`
- **Authentication**: None (Standard `api` middleware)
- **Headers**:
  - `Content-Type: application/json`
  - `Accept: application/json`

---

## Request Payload Requirements

The payload consists of two primary root objects: `user` (customer details) and `order` (order details, totals, payment preferences, and items).

### Schema Definition

| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| **`user`** | `object` | **Yes** | Customer details object. |
| `user.name` | `string` | **Yes** | Customer's full name. |
| `user.phone` | `string` | **Yes** | Customer's phone number (used as customer identifier). |
| `user.area` | `string` | **Yes** | Customer delivery area/zone (stored in `customers.region`). |
| `user.address` | `string` | **Yes** | Detailed street address and delivery instructions. |
| **`order`** | `object` | **Yes** | Order information object. |
| `order.type` | `string` | **Yes** | Order type enum: `web_delivery` or `web_takeaway`. |
| `order.shiftId` | `integer` | **Yes** | Active shift ID. Must match the restaurant's currently open shift. |
| `order.orderNumber` | `string` | **Yes** | Unique order number from the web platform (e.g. `"WEB-10492"`). |
| `order.subTotal` | `numeric` | **Yes** | Web cart items subtotal before taxes/fees. |
| `order.tax` | `numeric` | **Yes** | Applied tax amount. |
| `order.service` | `numeric` | **Yes** | Delivery fee or service charge (also sets `customer.delivery_cost`). |
| `order.discount` | `numeric` | **Yes** | Applied discount amount. |
| `order.total` | `numeric` | **Yes** | Final total charged or payable by the customer. |
| `order.note` | `string` | *No* | General order notes or instructions (stored in `orders.order_notes`). |
| `order.webPreferences` | `object` | *No* | Online payment metadata (stored as JSON). |
| `order.webPreferences.payment_method` | `string` | *No* | Payment method: `cash`, `card`, or `talabat_card`. |
| `order.webPreferences.transaction_id` | `string` | *No* | Payment gateway transaction/reference ID if paid online. |
| **`order.items`** | `array` | **Yes** | Array of cart items. |
| `order.items.*.quantity` | `numeric` | **Yes** | Number of units ordered (min: `0.001`). |
| `order.items.*.notes` | `string` | *No* | Item-specific kitchen instructions/modifications. |
| **`order.items.*.posRefObj`** | `array` | **Yes** | List of internal POS product references mapped to this item. |
| `order.items.*.posRefObj.*.productRef` | `string` | **Yes** | Matches `products.product_ref` in POS database. |
| `order.items.*.posRefObj.*.quantity` | `numeric` | **Yes** | Multiplier factor for this product reference (min: `0.001`). |

---

### Deep Dive: `posRefObj` Mapping Pattern

Online items frequently differ from raw POS inventory items (e.g. a meal combo on the website maps to a sandwich and a soda in the POS, or a single website product maps directly 1-to-1).

The `posRefObj` array bridges the web catalog and the POS catalog:

1. **Simple 1-to-1 Product**:
   ```json
   {
     "quantity": 2,
     "notes": "بدون بصل",
     "posRefObj": [
       {
         "productRef": "BURGER-BEEF-SINGLE",
         "quantity": 1
       }
     ]
   }
   ```
   *Result*: Creates 1 POS order item for `BURGER-BEEF-SINGLE` with quantity = `2 * 1 = 2`.

2. **Combo / Meal Bundle**:
   ```json
   {
     "quantity": 1,
     "notes": "بيبسي دايت",
     "posRefObj": [
       {
         "productRef": "BURGER-BEEF-SINGLE",
         "quantity": 1
       },
       {
         "productRef": "FRIES-LARGE",
         "quantity": 1
       },
       {
         "productRef": "PEPSI-CAN",
         "quantity": 1
       }
     ]
   }
   ```
   *Result*: Creates 3 distinct POS order item rows under the order, accurately deducting inventory and computing individual item costs.

---

## Request Example

### `POST /api/web-orders/place-order`

```json
{
  "user": {
    "name": "أحمد مصطفى",
    "phone": "01012345678",
    "area": "المعادي",
    "address": "شارع 9، عمارة 15، الدور 3، شقة 7"
  },
  "order": {
    "type": "web_delivery",
    "shiftId": 42,
    "orderNumber": "WEB-10542",
    "subTotal": 320.00,
    "tax": 44.80,
    "service": 25.00,
    "discount": 20.00,
    "total": 369.80,
    "note": "يرجى الاتصال عند الوصول",
    "webPreferences": {
      "payment_method": "cash",
      "transaction_id": null
    },
    "items": [
      {
        "quantity": 2,
        "notes": "اللحم مستوي جيداً",
        "posRefObj": [
          {
            "productRef": "BURGER-DBL-CHEESE",
            "quantity": 1
          }
        ]
      },
      {
        "quantity": 1,
        "notes": "",
        "posRefObj": [
          {
            "productRef": "FRIES-LG",
            "quantity": 1
          }
        ]
      }
    ]
  }
}
```

---

## Responses

### 1. Success Response (`200 OK`)

Returned when the order is successfully validated, persisted to the database, and dispatched to the kitchen/cashier dashboards.

```json
{
  "message": "تم إنشاء الطلب بنجاح"
}
```

---

### 2. Validation Failure (`422 Unprocessable Content`)

Returned by Laravel's validation engine if required fields are missing, invalid, or do not adhere to rules (e.g. invalid `order.type`, non-numeric quantities).

```json
{
  "message": "The order.type field must be one of: web_delivery, web_takeaway.",
  "errors": {
    "order.type": [
      "The order.type field must be one of: web_delivery, web_takeaway."
    ],
    "order.shiftId": [
      "The order.shiftId field is required."
    ],
    "order.items.0.posRefObj.0.productRef": [
      "The order.items.0.posRefObj.0.productRef field is required."
    ]
  }
}
```

---

### 3. Business Logic Error (`400 Bad Request`)

Returned when business rules fail during execution. Common scenarios:

#### Scenario A: Shift Mismatch / No Active Shift
If the passed `order.shiftId` is not equal to the currently open shift:
```json
{
  "message": "فشل في إنشاء الطلب",
  "error": "الوردية غير صحيحة"
}
```

#### Scenario B: Unrecognized Product References
If any `productRef` in `posRefObj` does not match an existing product in the POS database:
```json
{
  "message": "فشل في إنشاء الطلب",
  "error": "منتجات غير موجودة: [\"INVALID-REF-101\"]"
}
```

---

## Post-Creation Processing & Lifecycle

Once created through this endpoint:

1. **Initial State**:
   - Order status is set to `OrderStatus::PENDING` (`pending`).
   - Payment status is set to `PaymentStatus::PENDING` (`pending`).
2. **Broadcasting**:
   - The event `App\Events\Orders\WebOrderReceived` is dispatched.
   - Cashier terminals connected via Reverb WebSockets immediately receive sound alerts and display the new order in the **Web Orders** screen (`/orders/web-orders`).
3. **Status Updates Webhook**:
   When cashiers update the status of the order (Accept, Reject, Out for Delivery, Complete), Larament sends an HTTP notification webhook back to the website URL configured in settings (`SettingKey::WEBSITE_URL`):
   - **Target**: `{website_url}/api/order-status`
   - **Method**: `POST`
   - **Payload**:
     ```json
     {
       "orderNumber": "WEB-10542",
       "status": "processing"
     }
     ```
   - Possible statuses:
     - `processing`: Order accepted by restaurant.
     - `cancelled`: Order rejected / cancelled.
     - `out_for_delivery`: Delivery driver dispatched.
     - `completed`: Order delivered / finalized.

---

## Client Integration Checklist

- [ ] Call `GET /api/can-accept-order` before allowing the customer to checkout.
- [ ] Call `GET /api/get-shift-id` and inject the received integer into `order.shiftId`.
- [ ] Ensure `order.type` is strictly either `web_delivery` or `web_takeaway`.
- [ ] Validate that all `productRef` values in `posRefObj` match existing POS `product_ref` identifiers.
- [ ] Ensure currency calculations align with EGP standards.
- [ ] Handle `400 Bad Request` and `422 Unprocessable Content` gracefully on the frontend.
