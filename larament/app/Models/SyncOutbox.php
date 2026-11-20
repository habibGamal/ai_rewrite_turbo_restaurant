<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncOutbox extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sync_outbox';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'doc_type',
        'doc_uuid',
        'doc_key',
        'op',
        'priority',
        'occurred_at',
        'batch_id',
        'sent_at',
        'attempts',
        'next_attempt_at',
        'failed_at',
        'last_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'attempts' => 'integer',
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the batch associated with this outbox entry.
     *
     * @return BelongsTo<SyncBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'batch_id');
    }
}
