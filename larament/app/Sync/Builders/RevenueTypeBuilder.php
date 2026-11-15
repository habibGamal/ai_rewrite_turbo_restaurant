<?php

namespace App\Sync\Builders;

use App\Models\RevenueType;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;

class RevenueTypeBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::RevenueType;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var RevenueType|null $type */
        $type = RevenueType::query()->where('uuid', $uuid)->first();

        if (! $type) {
            return null;
        }

        return [
            'name' => $type->name,
            'is_active' => $type->is_active,
            'sort_order' => $type->sort_order,
        ];
    }
}
