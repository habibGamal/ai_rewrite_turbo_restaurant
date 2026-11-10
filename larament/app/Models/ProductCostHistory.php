<?php

namespace App\Models;

use App\Costing\CostingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProductCostHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'old_cost', 'new_cost', 'method', 'source_type', 'source_id', 'user_id'];

    protected $casts = ['old_cost' => 'decimal:4', 'new_cost' => 'decimal:4', 'method' => CostingMethod::class];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
