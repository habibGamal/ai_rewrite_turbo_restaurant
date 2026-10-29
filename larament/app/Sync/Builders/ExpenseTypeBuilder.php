<?php

namespace App\Sync\Builders;

use App\Models\ExpenceType;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;

class ExpenseTypeBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::ExpenseType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var ExpenceType|null $expenseType */
        $expenseType = ExpenceType::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $expenseType) {
            return null;
        }

        $category = $expenseType->category->value;

        return [
            'name' => $expenseType->name,
            'category' => $category,
            'avg_month_rate' => Payload::money($expenseType->avg_month_rate),
        ];
    }
}
