<?php

namespace App\Core\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The one way to write an audit row. Callers record inside their own
 * transaction, so a rolled-back edit leaves no audit row behind.
 *
 * The shift and business date come from the audited model (a Shift itself,
 * or anything with a shift_id) unless the caller passes them.
 */
class AuditLogger
{
    /**
     * Attributes never copied into old/new values.
     *
     * @var list<string>
     */
    private const IGNORED = ['created_at', 'updated_at', 'password', 'remember_token'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(
        Model $model,
        AuditAction $action,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        ?string $context = null,
        ?Shift $shift = null,
    ): AuditLog {
        $shift ??= self::shiftOf($model);

        return AuditLog::query()->create([
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'shift_id' => $shift?->getKey(),
            'business_date' => $shift?->getAttribute('business_date'),
            'user_id' => Auth::id(),
            'action' => $action,
            'old_values' => self::clean($old) ?: null,
            'new_values' => self::clean($new) ?: null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'context' => $context,
        ]);
    }

    /**
     * Record a model that was just created: all its attributes are the new values.
     */
    public static function created(Model $model, ?string $reason = null, ?string $context = null, ?Shift $shift = null): AuditLog
    {
        return self::record($model, AuditAction::CREATED, [], $model->getAttributes(), $reason, $context, $shift);
    }

    /**
     * Record a model that was just saved: only the changed attributes, before and after.
     * Take $original with getOriginal() before save(), and call this right after it.
     *
     * @param  array<string, mixed>  $original
     */
    public static function updated(Model $model, array $original, ?string $reason = null, ?string $context = null, ?Shift $shift = null): AuditLog
    {
        $new = array_diff_key($model->getChanges(), array_flip(self::IGNORED));
        $old = array_intersect_key($original, $new);

        return self::record($model, AuditAction::UPDATED, $old, $new, $reason, $context, $shift);
    }

    /**
     * Record a model that was (or is about to be) deleted: all its attributes are the old values.
     */
    public static function deleted(Model $model, ?string $reason = null, ?string $context = null, ?Shift $shift = null): AuditLog
    {
        return self::record($model, AuditAction::DELETED, $model->getAttributes(), [], $reason, $context, $shift);
    }

    private static function shiftOf(Model $model): ?Shift
    {
        if ($model instanceof Shift) {
            return $model;
        }

        $shiftId = $model->getAttribute('shift_id');

        return is_numeric($shiftId) ? Shift::query()->find((int) $shiftId) : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function clean(array $values): array
    {
        $values = array_diff_key($values, array_flip(self::IGNORED));

        return array_map(
            fn ($value) => $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value),
            $values,
        );
    }
}
