<?php

namespace App\Sync\Builders;

use App\Models\Category;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;

class CategoryBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Category;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var Category|null $category */
        $category = Category::query()
            ->where('uuid', $uuid)
            ->first();

        if (! $category) {
            return null;
        }

        return [
            'name' => $category->name,
        ];
    }
}
