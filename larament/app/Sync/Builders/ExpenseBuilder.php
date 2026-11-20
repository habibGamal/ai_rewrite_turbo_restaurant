<?php

namespace App\Sync\Builders;

use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\TerminalSession;
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

        /** @var Shift|null $shift */
        $shift = $expense->shift()->first();

        /** @var ExpenceType|null $expenseType */
        $expenseType = $expense->expenceType()->first();

        return [
            'shift_uuid' => $shift ? $shift->getAttribute('uuid') : null,
            'terminal_session_uuid' => TerminalSession::uuidOf($expense->getAttribute('terminal_session_id')),
            'business_date' => Payload::date($shift ? $shift->getAttribute('business_date') : null),
            'expense_type_uuid' => $expenseType ? $expenseType->getAttribute('uuid') : null,
            'amount' => Payload::money($expense->amount),
            'notes' => $expense->notes,
        ];
    }
}
