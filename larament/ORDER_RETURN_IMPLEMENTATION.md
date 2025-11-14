# Order Return Functionality - Implementation Summary

## Overview
Implemented a comprehensive order return system for the Laravel + FilamentPHP restaurant management application. The system allows admins to process full or partial returns for completed orders with flexible refund distribution and optional stock reversal.

## Database Structure

### New Tables Created

1. **orders table modification**
   - Added `return_status` column (string, nullable, default: 'none')
   - Tracks: none, partial_return, full_return

2. **order_returns table**
   - id, order_id, user_id, shift_id
   - total_refund (decimal)
   - reason (text)
   - reverse_stock (boolean, default: true)
   - timestamps

3. **order_return_items table**
   - id, order_return_id, order_item_id
   - quantity (decimal)
   - refund_amount (decimal)
   - timestamps

4. **refunds table**
   - id, order_return_id, payment_id (nullable)
   - amount (decimal)
   - method (string - cash/card/etc.)
   - timestamps

## Core Components

### 1. Enums
**`App\Enums\ReturnStatus`**
- NONE: 'لا يوجد إرجاع'
- PARTIAL_RETURN: 'إرجاع جزئي'
- FULL_RETURN: 'إرجاع كامل'
- Includes colors and icons for Filament badges

### 2. Models
**`App\Models\OrderReturn`**
- Relationships: order, user, shift, items, refunds
- Casts: total_refund (decimal), reverse_stock (boolean)

**`App\Models\OrderReturnItem`**
- Relationships: orderReturn, orderItem
- Casts: quantity, refund_amount (decimal)

**`App\Models\Refund`**
- Relationships: orderReturn, payment
- Casts: amount (decimal), method (PaymentMethod enum)

**`App\Models\Order` (updated)**
- Added: return_status cast, returns() relationship
- Computed attributes: total_refunded, return_status_string

### 3. Service Layer
**`App\Services\Orders\OrderReturnService`**

**Main Method:** `processReturn()`
- Parameters:
  - Order $order
  - array $returnItems (order_item_id, quantity, refund_amount)
  - string $reason
  - array $refundDistribution (payment_id, method, amount)
  - int $shiftId
  - bool $reverseStock

**Validation:**
- Validates return quantities against available quantities (original - already returned)
- Ensures refund distribution sum equals total refund
- Prevents over-returning items

**Stock Reversal:**
- Uses `OrderStockConversionService` to reverse stock movements
- Applies `MovementReason::ORDER_RETURN`
- Handles manufactured products (breaks down to components)

**Status Updates:**
- Automatically calculates return_status based on returned vs original quantities
- Updates Order.return_status to NONE/PARTIAL_RETURN/FULL_RETURN

**Helper Methods:**
- `getAvailableQuantityForReturn()` - calculates returnable quantity per item
- `getReturnSummary()` - provides return summary for an order

### 4. Filament Actions
**`App\Filament\Actions\ReturnOrderAction`**

**Features:**
- Available as both Action and TableAction
- Visible only for completed orders
- Modal width: 7xl for better UX

**Form Schema:**
1. **Return Items Repeater**
   - Shows all order items with:
     - Product name (disabled)
     - Original quantity (disabled)
     - Available for return (disabled)
     - Return quantity (input with validation)
     - Refund amount (input)
   - Pre-populated with all items, admin selects what to return
   - Non-addable, non-deletable, non-reorderable

2. **Reason Textarea**
   - Required field for return reason
   - 3 rows

3. **Refund Distribution Repeater**
   - Method select (PaymentMethod enum)
   - Payment ID select (optional, linked to original payments)
   - Amount input
   - Pre-populated with proportional distribution based on original payments
   - Admin can modify amounts or add manual refunds
   - Helper text suggests proportional amounts

4. **Reverse Stock Toggle**
   - Default: true
   - Helper text explains stock reversal

5. **Warning Placeholder**
   - Alerts admin that operation cannot be undone

**Validation:**
- Filters out items with zero quantity
- Filters out refunds with zero amount
- Ensures at least one item and one refund method selected

### 5. Filament Resources
**`App\Filament\Resources\OrderReturnResource`**

**Table Columns:**
- Return ID
- Order number (linked)
- Customer name
- Total refund (money)
- Reverse stock (boolean icon)
- User name
- Shift ID
- Created at

**Filters:**
- Date range
- Order ID (searchable)
- Reverse stock (ternary)

**Infolist Sections:**
1. Return Information
   - ID, order link, customer, total, reverse stock, user, shift, date

2. Return Reason
   - Collapsible section with reason text

3. Refund Details
   - Repeatable entry showing each refund:
     - Payment method (badge)
     - Linked payment ID (if applicable)
     - Amount

4. Statistics
   - Items count
   - Total quantity returned
   - Refunds count

**Navigation:**
- Group: 'إدارة المطعم'
- Label: 'مرتجعات الطلبات'
- Icon: heroicon-o-arrow-uturn-left
- Sort: 2

### 6. Relation Managers

**`ReturnedItemsRelationManager`** (for OrderReturnResource)
- Shows items in a return
- Columns: product name, returned quantity, refund amount, original quantity, original price

**`OrderReturnsRelationManager`** (for OrderResource)
- Shows all returns for an order
- Columns: return ID, total refund, reverse stock, user, shift, date
- View action links to OrderReturnResource

### 7. OrderResource Integration

**Table Updates:**
- Added return_status column (badge, toggleable)
- Added return_status filter
- Added ReturnOrderAction to actions

**Infolist Updates:**
- Added return_status badge to order information
- Added total_refunded to financial details (visible when > 0)

**Relations:**
- Added OrderReturnsRelationManager

**Query:**
- Eager loads 'returns' relationship

## Key Features

### Multiple Partial Returns Support
- Tracks all return operations per order
- Validates against previously returned quantities
- Allows multiple return operations until all items returned

### Flexible Refund Distribution
- Admin can distribute refunds across multiple payment methods
- Can link refunds to original payments or create manual refunds
- Suggests proportional distribution but allows override
- Validates that distribution sum equals total refund

### Optional Stock Reversal
- Admin controls whether returned items go back to stock
- Uses same stock conversion logic as order cancellation
- Handles manufactured products correctly (breaks into components)
- Applies MovementReason::ORDER_RETURN for tracking

### No Impact on Original Order
- Return operations don't modify order.profit
- Return data tracked separately
- Order status remains COMPLETED
- New return_status field tracks return state independently

### Comprehensive Tracking
- Links returns to user, shift, and order
- Stores reason for each return
- Tracks refund methods and amounts
- Maintains audit trail

## Usage Flow

1. Admin navigates to Orders list or view
2. Clicks "إرجاع" button on completed order
3. Modal opens with:
   - All order items listed
   - Admin selects items to return with quantities
   - Admin enters refund amounts per item
   - Admin specifies refund distribution (can modify suggested amounts)
   - Admin enters return reason
   - Admin chooses whether to reverse stock
4. System validates:
   - Return quantities don't exceed available
   - Refund distribution equals total
5. System processes:
   - Creates OrderReturn record
   - Creates OrderReturnItem records
   - Creates Refund records
   - Reverses stock if requested
   - Updates order return_status
6. Success notification with total refund amount
7. Return appears in:
   - OrderReturns resource
   - Order's returns relation manager
   - Order return_status badge

## Files Created

### Migrations
- `2025_11_14_000001_add_return_status_to_orders_table.php`
- `2025_11_14_000002_create_order_returns_table.php`
- `2025_11_14_000003_create_order_return_items_table.php`
- `2025_11_14_000004_create_refunds_table.php`

### Enums
- `app/Enums/ReturnStatus.php`

### Models
- `app/Models/OrderReturn.php`
- `app/Models/OrderReturnItem.php`
- `app/Models/Refund.php`

### Services
- `app/Services/Orders/OrderReturnService.php`

### Actions
- `app/Filament/Actions/ReturnOrderAction.php`

### Resources
- `app/Filament/Resources/OrderReturnResource.php`
- `app/Filament/Resources/OrderReturnResource/Pages/ListOrderReturns.php`
- `app/Filament/Resources/OrderReturnResource/Pages/ViewOrderReturn.php`

### Relation Managers
- `app/Filament/Resources/OrderReturnResource/RelationManagers/ReturnedItemsRelationManager.php`
- `app/Filament/Resources/OrderResource/RelationManagers/OrderReturnsRelationManager.php`

## Files Modified
- `app/Models/Order.php` - Added return relationships and computed attributes
- `app/Filament/Resources/OrderResource.php` - Integrated return functionality

## Next Steps

1. **Run Migrations:**
   ```bash
   php artisan migrate
   ```

2. **Test the functionality:**
   - Create a test order and complete it
   - Try returning items
   - Verify stock changes if reverse_stock is enabled
   - Check refunds are created correctly
   - Try multiple partial returns

3. **Optional Enhancements:**
   - Add return printing functionality
   - Add dashboard widgets for return statistics
   - Add notifications for returns
   - Add permission controls for return actions
   - Integrate with shift reports

## Notes

- Returns don't affect original order profit calculation
- Stock reversal uses the same logic as order cancellation
- Multiple partial returns are supported
- Refund distribution is flexible and admin-controlled
- All operations are logged for audit purposes
- System validates all inputs to prevent data inconsistencies
