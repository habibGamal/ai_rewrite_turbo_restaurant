<?php

namespace App\Sync\Builders;

use App\Models\Supplier;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;

class SupplierBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Supplier;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $supplier) {
            return null;
        }

        return [
            'name' => $supplier->name,
            'phone' => $supplier->phone,
        ];
    }
}
