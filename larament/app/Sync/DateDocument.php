<?php

namespace App\Sync;

use App\Models\PlatformConnection;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class DateDocument
{
    /**
     * Generate a deterministic UUIDv5 for a date-keyed document.
     */
    public static function uuid(DocumentType|string $type, string $date, ?string $branchUuid = null): string
    {
        $branchUuid = $branchUuid ?? PlatformConnection::active()?->branch_uuid;

        if (! $branchUuid) {
            throw new RuntimeException('Cannot generate DateDocument uuid without an active platform connection or branch uuid.');
        }

        $typeValue = $type instanceof DocumentType ? $type->value : (string) $type;

        return Uuid::uuid5($branchUuid, "{$typeValue}:{$date}")->toString();
    }
}
