<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureFlagAudit extends Model
{
    protected $fillable = [
        'feature_key',
        'old_value',
        'new_value',
        'old_text',
        'new_text',
        'user_id',
        'changed_at',
    ];

    protected $casts = [
        'old_value' => 'boolean',
        'new_value' => 'boolean',
        'changed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
