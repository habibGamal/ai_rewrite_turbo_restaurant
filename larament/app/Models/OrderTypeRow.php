<?php

namespace App\Models;

use App\Enums\OrderBehavior;
use Illuminate\Database\Eloquent\Model;

class OrderTypeRow extends Model
{
    protected $table = 'order_types';

    protected $fillable = [
        'code',
        'label',
        'color',
        'icon',
        'is_active',
        'is_system',
        'behavior',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'behavior' => OrderBehavior::class,
    ];
}
