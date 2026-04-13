<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemChange extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'change_type',
        'old_quantity',
        'new_quantity',
        'delta',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'delta' => 'decimal:3',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
