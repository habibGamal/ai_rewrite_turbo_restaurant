<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTerminalSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalSellerSettlement extends Model
{
    use BelongsToTerminalSession;

    protected $guarded = [];

    /** @return BelongsTo<ExternalSeller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(ExternalSeller::class, 'external_seller_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
