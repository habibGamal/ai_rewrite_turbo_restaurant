<?php

namespace App\Sync\Builders;

use App\Sync\DocumentType;

interface DocumentBuilder
{
    /**
     * The document type this builder produces.
     */
    public function type(): DocumentType;

    /**
     * Build the payload snapshot for the given document uuid.
     * Returns null if the record no longer exists (tombstone / delete).
     *
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array;

    /**
     * Set an optional document key (used by date-keyed documents).
     */
    public function withKey(?string $key): static;
}
