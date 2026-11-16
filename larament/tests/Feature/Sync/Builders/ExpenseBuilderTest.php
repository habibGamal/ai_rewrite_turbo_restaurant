<?php

use App\Enums\ExpenseCategory;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Shift;
use App\Sync\Builders\ExpenseBuilder;
use Illuminate\Support\Str;

it('builds an expense snapshot', function () {
    pairPlatform();

    $shift = Shift::factory()->create([
        'business_date' => '2026-10-29',
    ]);

    $expenseType = ExpenceType::factory()->create([
        'category' => ExpenseCategory::Utilities,
    ]);

    $expense = Expense::factory()->create([
        'shift_id' => $shift->id,
        'expence_type_id' => $expenseType->id,
        'amount' => 75.50,
        'notes' => 'Electricity bill',
    ]);

    $data = app(ExpenseBuilder::class)->build($expense->uuid);

    expect($data)->toEqual([
        'shift_uuid' => $shift->uuid,
        'terminal_session_uuid' => $expense->terminalSession?->uuid,
        'business_date' => '2026-10-29',
        'expense_type_uuid' => $expenseType->uuid,
        'amount' => '75.50',
        'notes' => 'Electricity bill',
    ]);
});

it('returns null for an unknown expense uuid', function () {
    expect(app(ExpenseBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
