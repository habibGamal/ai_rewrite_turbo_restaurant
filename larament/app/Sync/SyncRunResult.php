<?php

namespace App\Sync;

readonly class SyncRunResult
{
    public function __construct(
        public int $batches = 0,
        public int $sent = 0,
        public int $rejected = 0,
        public int $failed = 0,
        public ?string $error = null,
    ) {}
}
