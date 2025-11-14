# Order Return Functionality - Update Summary

## Changes Made

### 1. Return Action Moved to View Page ✅
**Location:** `app/Filament/Resources/OrderResource/Pages/ViewOrder.php`

- Added `ReturnOrderAction::make()` to header actions
- Removed `ReturnOrderAction::table()` from OrderResource table actions
- Now the return button appears only in the order view page, providing better context

### 2. Auto-Calculate Refund Amounts ✅
**Location:** `app/Filament/Actions/ReturnOrderAction.php`

**Changes:**
- Added `unit_price` field (disabled, shows item price)
- Made `refund_amount` field disabled and auto-calculated
- Added live update on `quantity` field that:
  - Caps quantity to available quantity
  - Automatically calculates: `refund_amount = quantity × unit_price`
  - Updates in real-time when quantity changes

**User Experience:**
- Admin selects quantity
- Refund amount calculates automatically
- No manual calculation needed

### 3. Simplified Refund Distribution ✅

**Database Changes:**
- Removed `payment_id` column from `refunds` table migration
- Removed `payment_id` from Refund model fillable
- Removed `payment()` relationship from Refund model

**Form Changes:**
- Removed `payment_id` select field from refund distribution
- Added new section "معلومات الدفع الأصلية" showing:
  - Total order amount
  - Original payment methods and amounts used
- Simplified refund repeater to only:
  - Method select (cash/card/etc.)
  - Amount input
- Pre-populates with original payment methods (amount = 0 for admin to fill)
- Added hint: "يجب أن يساوي مجموع مبالغ الاسترجاع إجمالي مبالغ الأصناف المرتجعة"

**Service Changes:**
- Updated `OrderReturnService::processReturn()` signature
- Removed `payment_id` from refund creation
- Simplified refund validation

**User Experience:**
- Admin sees original payment breakdown
- Admin chooses refund method(s) independently
- No forced link between original payments and refunds
- More flexibility in refund distribution

## Updated Files

1. ✅ `app/Filament/Resources/OrderResource/Pages/ViewOrder.php`
   - Added return action to header

2. ✅ `app/Filament/Resources/OrderResource.php`
   - Removed ReturnOrderAction from table actions
   - Removed ReturnOrderAction import

3. ✅ `app/Filament/Actions/ReturnOrderAction.php`
   - Completely redesigned form schema
   - Added auto-calculation logic
   - Added payment info section
   - Simplified refund distribution
   - Removed table action method

4. ✅ `database/migrations/2025_11_14_000004_create_refunds_table.php`
   - Removed payment_id column

5. ✅ `app/Models/Refund.php`
   - Removed payment_id from fillable
   - Removed payment() relationship

6. ✅ `app/Services/Orders/OrderReturnService.php`
   - Updated processReturn() parameter documentation
   - Removed payment_id from refund creation

7. ✅ `app/Filament/Resources/OrderReturnResource.php`
   - Updated infolist to remove payment_id display
   - Adjusted columns in refund repeatable entry

## New Form Structure

### Return Items Section
```
| Product Name | Original Qty | Available | Unit Price | Return Qty | Refund Amount |
|--------------|--------------|-----------|------------|------------|---------------|
| Item 1       | 5            | 5         | 10.00 ج.م  | [input]    | [auto-calc]   |
| Item 2       | 3            | 2         | 15.00 ج.م  | [input]    | [auto-calc]   |
```

### Payment Info Section (Collapsible)
```
إجمالي الطلب: 150.00 ج.م

طرق الدفع المستخدمة:
• نقدي: 100.00 ج.م
• فيزا: 50.00 ج.م
```

### Refund Distribution Section
```
| Method    | Amount     |
|-----------|------------|
| نقدي      | [input]    |
| فيزا      | [input]    |
[+ إضافة طريقة استرجاع]
```

### Other Fields
- Return Reason (required textarea)
- Reverse Stock (toggle, default: true)
- Warning message

## Benefits

1. **Better UX Flow:**
   - Return action in context (view page)
   - Less clutter in table view

2. **Reduced Errors:**
   - Auto-calculation prevents math mistakes
   - Automatic quantity capping

3. **More Flexibility:**
   - Admin controls refund distribution
   - Not tied to original payment records
   - Can refund via different methods

4. **Clearer Information:**
   - Shows payment history for reference
   - Clear hint about total validation
   - Better organized form

5. **Simplified Data Model:**
   - Less complex relationships
   - Easier to maintain
   - More straightforward queries

## Migration Steps

1. **Drop existing refunds table (if any data exists):**
   ```bash
   php artisan migrate:rollback --step=1
   ```

2. **Run fresh migration:**
   ```bash
   php artisan migrate
   ```

3. **Clear cache:**
   ```bash
   php artisan optimize:clear
   ```

## Testing Checklist

- [ ] Open an order view page
- [ ] Click "إرجاع الطلب" button in header
- [ ] Verify payment info section shows correctly
- [ ] Change quantity and verify refund amount auto-calculates
- [ ] Try quantity > available (should cap automatically)
- [ ] Add/remove refund distribution entries
- [ ] Submit with mismatched totals (should validate)
- [ ] Submit valid return and verify success
- [ ] Check OrderReturn resource shows refunds without payment_id
- [ ] Verify stock reversal works if enabled
- [ ] Test multiple partial returns on same order

## Notes

- The return action now only appears in the view page, not in the table
- Refund amounts calculate automatically based on quantity × unit price
- Admin has full control over refund method distribution
- System still validates that refund distribution sum equals total refund
- Original payment information shown for reference only
