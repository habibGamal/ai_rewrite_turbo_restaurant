<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemChange extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'product_id',
        'product_name',
        'change_type',
        'old_quantity',
        'new_quantity',
        'delta',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'old_quantity' => 'decimal:3',
        'new_quantity' => 'decimal:3',
        'delta' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
