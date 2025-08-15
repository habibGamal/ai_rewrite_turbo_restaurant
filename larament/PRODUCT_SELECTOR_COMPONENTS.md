# Product Selector Components

This document explains how to use the new Product Selector components that replace the ProductImporterAction functionality.

## Components

### 1. ProductSelector

A simple select component for adding products to forms with items (like invoices, waste records, etc.).

**Usage:**
```php
use App\Filament\Components\Forms\ProductSelector;

// Basic usage
ProductSelector::make()
    ->columnSpanFull(),

// With additional properties
ProductSelector::make()
    ->additionalProps(function (Product $product) {
        return [
            'stock_quantity' => $product->inventoryItem?->quantity ?? 0,
        ];
    })
    ->columnSpanFull(),
```

**Features:**
- Displays products with price and category information
- Automatically adds selected products to the `items` field
- Prevents duplicate product addition
- Automatically calculates totals
- Resets the select after adding a product
- Shows success/warning notifications

### 2. StocktakingProductSelector

A specialized select component for stocktaking operations.

**Usage:**
```php
use App\Filament\Components\Forms\StocktakingProductSelector;

StocktakingProductSelector::make()
    ->columnSpanFull(),
```

**Features:**
- Shows current stock quantities alongside product information
- Automatically sets stock_quantity and real_quantity fields
- Calculates stocktaking differences and totals
- Designed specifically for stocktaking operations

## Replaced Files

The following resources have been updated to use the new components:

1. **PurchaseInvoiceResource** - Uses `ProductSelector`
2. **ReturnPurchaseInvoiceResource** - Uses `ProductSelector`
3. **WasteResource** - Uses `ProductSelector` with stock quantity tracking
4. **StocktakingResource** - Uses `StocktakingProductSelector`

## Expected Form Structure

Both components expect the form to have:
- An `items` field (typically a TableRepeater)
- A `total` field for automatic calculation

The components will automatically:
1. Add new items to the `items` array
2. Update the `total` field
3. Reset the selector after adding
4. Show appropriate notifications

## Migration Benefits

- **Simpler UI**: No modal dialogs, just a simple select
- **Better UX**: Immediate feedback and instant addition
- **Cleaner Code**: Less complex than the modal-based actions
- **Consistent**: Same interface across all resources
- **Performance**: No need to load large modal forms

## Customization

You can extend these components by:
1. Adding custom validation
2. Modifying the display format
3. Adding additional properties via the `additionalProps` callback
4. Customizing notifications
