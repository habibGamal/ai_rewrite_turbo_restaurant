# Kitchen Printing Implementation Documentation

## Overview
This implementation provides server-side kitchen printing using Browsershot to generate HTML-based receipts, eliminating the need for client-side image generation.

## Key Components

### 1. Kitchen Template (`resources/views/print/kitchen-template.blade.php`)
- Arabic RTL layout optimized for thermal printers (500px width)
- Displays order number, type, date, and table information
- Shows products and quantities in a structured table format
- Includes support for product notes

### 2. PrintService Methods

#### `printKitchenViaBrowsershot($orderId, $items)`
- Main entry point for kitchen printing
- Maps products to their assigned printers
- Handles multiple printers per product
- Provides error handling for individual printer failures

#### `printKitchenToPrinter($order, $orderItems, $printerId)`
- Prints to a specific printer
- Generates HTML using Blade template
- Converts HTML to image using Browsershot
- Sends image to thermal printer via escpos-php

#### `prepareKitchenItems($items)`
- Validates and normalizes item data
- Fetches product names if not provided
- Ensures data consistency

### 3. Frontend Updates (`PrintInKitchenModal.tsx`)
- Simplified to send item data directly to backend
- Removed client-side image generation logic
- Sends data in format: `{orderId, items: [{product_id, name, quantity, notes}]}`

### 4. Controller Updates (`OrderController.php`)
- Updated `printInKitchen` method to use new implementation
- Validates incoming data structure
- Provides proper error handling and user feedback

## Data Flow

1. **Frontend**: User selects items and quantities in PrintInKitchenModal
2. **API Call**: POST to `/print-in-kitchen` with `{orderId, items}`
3. **Backend Processing**:
   - Load order details
   - Prepare and validate item data
   - Map products to their assigned printers
   - For each printer:
     - Generate HTML template
     - Convert to image via Browsershot
     - Send to thermal printer
4. **Response**: Success/error message to frontend

## Product-Printer Relationships

Products are linked to printers via the `printer_product` pivot table:
- A product can be assigned to multiple printers
- A printer can handle multiple products
- Kitchen items are automatically distributed to all assigned printers

## Error Handling

- Individual printer failures don't stop other printers
- Validation for missing required fields
- Logging of all operations for debugging
- User-friendly error messages in Arabic

## Configuration

### Required Settings
- Printer IP addresses in the database
- Browsershot dependencies (Node.js, Puppeteer)
- Arabic fonts for proper text rendering

### Thermal Printer Support
- Works with network printers (IP:9100)
- Works with Windows shared printers (UNC paths)
- Works with USB/local printers (printer names)

## Advantages

1. **Server-side Processing**: Eliminates client-side dependencies
2. **Consistent Rendering**: HTML/CSS provides precise layout control
3. **Arabic Support**: Proper RTL text rendering with web fonts
4. **Scalability**: Can handle multiple printers efficiently
5. **Maintainability**: Template-based design is easy to modify

## Usage Example

```javascript
// Frontend call
await axios.post('/print-in-kitchen', {
    orderId: 123,
    items: [
        {
            product_id: 1,
            name: "برجر كلاسيك",
            quantity: 2,
            notes: "بدون مايونيز"
        }
    ]
});
```

## Future Enhancements

1. Add printer queue management
2. Implement print status tracking
3. Add support for custom templates per printer
4. Include order totals and customer info in kitchen receipts
