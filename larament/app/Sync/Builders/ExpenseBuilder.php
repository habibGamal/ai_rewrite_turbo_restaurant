<?php

namespace App\Sync\Builders;

use App\Models\Expense;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class ExpenseBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Expense;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Expense|null $expense */
        $expense = Expense::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $expense) {
            return null;
        }

        /** @var \App\Models\Shift|null $shift */
        $shift = $expense->shift()->first();

        /** @var \App\Models\ExpenceType|null $expenseType */
        $expenseType = $expense->expenceType()->first();

        return [
            'shift_uuid' => $shift ? $shift->getAttribute('uuid') : null,
            'business_date' => Payload::date($shift ? $shift->getAttribute('business_date') : null),
            'expense_type_uuid' => $expenseType ? $expenseType->getAttribute('uuid') : null,
            'amount' => Payload::money($expense->amount),
            'notes' => $expense->notes,
        ];
    }
}
