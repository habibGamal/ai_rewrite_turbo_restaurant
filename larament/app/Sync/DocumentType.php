<?php

namespace App\Sync;

enum DocumentType: string
{
    case Order = 'order';
    case OrderReturn = 'order_return';
    case Shift = 'shift';
    case Expense = 'expense';
    case PurchaseInvoice = 'purchase_invoice';
    case PurchaseReturn = 'purchase_return';
    case Waste = 'waste';
    case Stocktaking = 'stocktaking';
    case InventoryDay = 'inventory_day';
    case DaySummary = 'day_summary';
    case ProductGroup = 'product_group';
    case Product = 'product';
    case Recipe = 'recipe';
    case Category = 'category';
    case Supplier = 'supplier';
    case ExpenseType = 'expense_type';
    case Staff = 'staff';
    case AuditLog = 'audit_log';
    case Revenue = 'revenue';
    case RevenueType = 'revenue_type';
}
