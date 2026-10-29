<?php

namespace App\Sync\Builders;

use App\Models\User;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;

class StaffBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::Staff;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var User|null $user */
        $user = User::query()
            ->with('roles')
            ->where('uuid', $uuid)
            ->first();

        if (! $user) {
            return null;
        }

        $role = $user->getRoleNames()->first();

        return [
            'name' => $user->name,
            'role' => $role,
        ];
    }
}
