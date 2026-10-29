<?php

use App\Sync\BuilderRegistry;
use App\Sync\Builders\CategoryBuilder;
use App\Sync\Builders\ExpenseBuilder;
use App\Sync\Builders\ExpenseTypeBuilder;
use App\Sync\Builders\OrderBuilder;
use App\Sync\Builders\OrderReturnBuilder;
use App\Sync\Builders\ProductBuilder;
use App\Sync\Builders\PurchaseBuilder;
use App\Sync\Builders\RecipeBuilder;
use App\Sync\Builders\ShiftBuilder;
use App\Sync\Builders\StaffBuilder;
use App\Sync\Builders\StocktakingBuilder;
use App\Sync\Builders\SupplierBuilder;
use App\Sync\Builders\WasteBuilder;
use App\Sync\DocumentType;

it('resolves builders for all supported document types', function (DocumentType $type, string $expectedClass) {
    $builder = BuilderRegistry::for($type);

    expect($builder)->toBeInstanceOf($expectedClass)
        ->and($builder->type())->toBe($type);
})->with([
    [DocumentType::Order, OrderBuilder::class],
    [DocumentType::OrderReturn, OrderReturnBuilder::class],
    [DocumentType::Shift, ShiftBuilder::class],
    [DocumentType::Expense, ExpenseBuilder::class],
    [DocumentType::PurchaseInvoice, PurchaseBuilder::class],
    [DocumentType::PurchaseReturn, PurchaseBuilder::class],
    [DocumentType::Waste, WasteBuilder::class],
    [DocumentType::Stocktaking, StocktakingBuilder::class],
    [DocumentType::Product, ProductBuilder::class],
    [DocumentType::Recipe, RecipeBuilder::class],
    [DocumentType::Category, CategoryBuilder::class],
    [DocumentType::Supplier, SupplierBuilder::class],
    [DocumentType::ExpenseType, ExpenseTypeBuilder::class],
    [DocumentType::Staff, StaffBuilder::class],
]);

it('throws InvalidArgumentException for document types without a builder yet', function (DocumentType $type) {
    BuilderRegistry::for($type);
})->with([
    DocumentType::InventoryDay,
    DocumentType::DaySummary,
])->throws(InvalidArgumentException::class);
