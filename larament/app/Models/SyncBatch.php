<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncBatch extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sync_batches';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'seq_from',
        'seq_to',
        'docs_count',
        'status',
        'response',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq_from' => 'integer',
            'seq_to' => 'integer',
            'docs_count' => 'integer',
            'response' => 'array',
        ];
    }

    /**
     * Get the outbox records for this batch.
     *
     * @return HasMany<SyncOutbox, $this>
     */
    public function outboxRows(): HasMany
    {
        return $this->hasMany(SyncOutbox::class, 'batch_id');
    }
}
