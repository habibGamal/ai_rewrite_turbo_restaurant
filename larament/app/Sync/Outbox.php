<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use App\Models\SyncOutbox;

class Outbox
{
    /**
     * Record a document change in the sync outbox.
     * No-op when the POS is not paired with an active platform connection.
     */
    public static function record(
        DocumentType $type,
        string $uuid,
        string $op = 'upsert',
        int $priority = 5,
        ?string $key = null
    ): void {
        if (! PlatformConnection::active()) {
            return;
        }

        SyncOutbox::create([
            'doc_type' => $type->value,
            'doc_uuid' => $uuid,
            'doc_key' => $key,
            'op' => $op,
            'priority' => $priority,
            'occurred_at' => now(),
        ]);
    }
}
