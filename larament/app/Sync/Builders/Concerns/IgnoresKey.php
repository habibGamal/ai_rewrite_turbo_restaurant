<?php

namespace App\Sync\Builders\Concerns;

trait IgnoresKey
{
    /**
     * Key parameter ignored by standard UUID-based document builders.
     */
    public function withKey(?string $key): static
    {
        return $this;
    }
}
