<?php

namespace App\Sync\Builders;

use App\Models\AuditLog;
use App\Models\Concerns\HasSyncUuid;
use App\Sync\Builders\Concerns\IgnoresKey;
use App\Sync\DocumentType;
use App\Sync\Payload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

class AuditLogBuilder implements DocumentBuilder
{
    use IgnoresKey;

    public function type(): DocumentType
    {
        return DocumentType::AuditLog;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $uuid): ?array
    {
        /** @var AuditLog|null $log */
        $log = AuditLog::query()
            ->with(['shift', 'user'])
            ->where('uuid', $uuid)
            ->first();

        if (! $log) {
            return null;
        }

        $shift = $log->shift;
        $user = $log->user;

        return [
            'shift_uuid' => $shift?->getAttribute('uuid'),
            'business_date' => Payload::date($log->business_date),
            'user_uuid' => $user?->getAttribute('uuid'),
            'auditable_type' => Str::snake(class_basename(Relation::getMorphedModel($log->auditable_type) ?? $log->auditable_type)),
            'auditable_uuid' => $this->auditableUuid($log),
            'action' => $log->action->value,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'reason' => $log->reason,
            'context' => $log->context,
            'created_at' => Payload::ts($log->created_at),
        ];
    }

    /**
     * The audited row's sync uuid, when it still exists and has one. A
     * deleted row's uuid is still in old_values.
     */
    private function auditableUuid(AuditLog $log): ?string
    {
        $class = Relation::getMorphedModel($log->auditable_type) ?? $log->auditable_type;

        if (! is_a($class, Model::class, true)
            || $log->auditable_id === null
            || ! in_array(HasSyncUuid::class, class_uses_recursive($class), true)) {
            return $log->old_values['uuid'] ?? null;
        }

        $uuid = $class::query()->whereKey($log->auditable_id)->value('uuid');

        return is_string($uuid) ? $uuid : ($log->old_values['uuid'] ?? null);
    }
}
