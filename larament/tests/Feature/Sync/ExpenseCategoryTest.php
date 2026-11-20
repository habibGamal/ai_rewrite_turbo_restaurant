<?php

use App\Enums\ExpenseCategory;
use App\Filament\Resources\ExpenseTypes\Pages\CreateExpenseType;
use App\Filament\Resources\ExpenseTypes\Pages\ListExpenseTypes;
use App\Models\ExpenceType;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('defaults category to other', function () {
    $expenseType = ExpenceType::factory()->create();

    expect($expenseType->category)->toBe(ExpenseCategory::Other)
        ->and($expenseType->category->value)->toBe('other')
        ->and($expenseType->category->label())->toBe('أخرى');
});

it('lets an admin set the category from the expense type form', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    livewire(CreateExpenseType::class)
        ->fillForm([
            'name' => 'رواتب الموظفين',
            'category' => ExpenseCategory::Labor->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(ExpenceType::class, [
        'name' => 'رواتب الموظفين',
        'category' => 'labor',
    ]);
});

it('has correct Arabic labels for all categories', function () {
    expect(ExpenseCategory::Labor->label())->toBe('عمالة ورواتب')
        ->and(ExpenseCategory::Rent->label())->toBe('إيجار')
        ->and(ExpenseCategory::Utilities->label())->toBe('مرافق')
        ->and(ExpenseCategory::Marketing->label())->toBe('تسويق')
        ->and(ExpenseCategory::Maintenance->label())->toBe('صيانة')
        ->and(ExpenseCategory::RawMaterials->label())->toBe('خامات')
        ->and(ExpenseCategory::Other->label())->toBe('أخرى');
});

it('has category column on the expense types table', function () {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    livewire(ListExpenseTypes::class)
        ->assertTableColumnExists('category');
});
