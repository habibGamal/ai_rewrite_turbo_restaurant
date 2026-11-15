<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One sensitive change, written by App\Core\Audit\AuditLogger. Append-only:
 * rows are never updated or deleted, so the model refuses both.
 *
 * @property AuditAction $action
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends Model
{
    use HasSyncUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'auditable_type',
        'auditable_id',
        'shift_id',
        'business_date',
        'user_id',
        'action',
        'old_values',
        'new_values',
        'reason',
        'context',
    ];

    protected $casts = [
        'action' => AuditAction::class,
        'old_values' => 'array',
        'new_values' => 'array',
        'business_date' => 'date',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit log rows are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit log rows are append-only.');
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
