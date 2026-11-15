<?php

namespace App\Sync;

use App\Sync\Builders\CategoryBuilder;
use App\Sync\Builders\DaySummaryBuilder;
use App\Sync\Builders\DocumentBuilder;
use App\Sync\Builders\ExpenseBuilder;
use App\Sync\Builders\ExpenseTypeBuilder;
use App\Sync\Builders\InventoryDayBuilder;
use App\Sync\Builders\OrderBuilder;
use App\Sync\Builders\OrderReturnBuilder;
use App\Sync\Builders\ProductBuilder;
use App\Sync\Builders\ProductGroupBuilder;
use App\Sync\Builders\PurchaseBuilder;
use App\Sync\Builders\RecipeBuilder;
use App\Sync\Builders\ShiftBuilder;
use App\Sync\Builders\StaffBuilder;
use App\Sync\Builders\StocktakingBuilder;
use App\Sync\Builders\SupplierBuilder;
use App\Sync\Builders\WasteBuilder;

class BuilderRegistry
{
    /**
     * Resolve the document builder for the given document type.
     */
    public static function for(DocumentType $type): DocumentBuilder
    {
        return match ($type) {
            DocumentType::Order => app(OrderBuilder::class),
            DocumentType::OrderReturn => app(OrderReturnBuilder::class),
            DocumentType::Shift => app(ShiftBuilder::class),
            DocumentType::Expense => app(ExpenseBuilder::class),
            DocumentType::PurchaseInvoice => new PurchaseBuilder(DocumentType::PurchaseInvoice),
            DocumentType::PurchaseReturn => new PurchaseBuilder(DocumentType::PurchaseReturn),
            DocumentType::Waste => app(WasteBuilder::class),
            DocumentType::Stocktaking => app(StocktakingBuilder::class),
            DocumentType::ProductGroup => app(ProductGroupBuilder::class),
            DocumentType::Product => app(ProductBuilder::class),
            DocumentType::Recipe => app(RecipeBuilder::class),
            DocumentType::Category => app(CategoryBuilder::class),
            DocumentType::Supplier => app(SupplierBuilder::class),
            DocumentType::ExpenseType => app(ExpenseTypeBuilder::class),
            DocumentType::Staff => app(StaffBuilder::class),
            DocumentType::InventoryDay => app(InventoryDayBuilder::class),
            DocumentType::DaySummary => app(DaySummaryBuilder::class),
        };
    }
}
