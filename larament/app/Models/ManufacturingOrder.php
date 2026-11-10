<?php

namespace App\Models;

use App\Exceptions\ManufacturingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManufacturingOrder extends Model
{
    protected $fillable = [
        'manufacturing_recipe_id', 'source_warehouse_id', 'destination_warehouse_id',
        'planned_main_quantity', 'notes', 'user_id', 'closed_at', 'closed_by',
        'business_date', 'total_input_cost',
    ];

    protected $casts = [
        'planned_main_quantity' => 'decimal:3',
        'total_input_cost' => 'decimal:2',
        'closed_at' => 'datetime',
        'business_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $order->assertDraft();
            if ($order->isDirty(['manufacturing_recipe_id', 'source_warehouse_id', 'destination_warehouse_id', 'planned_main_quantity', 'user_id'])) {
                throw new ManufacturingException('لا يمكن تغيير بيانات التخطيط بعد نسخ الوصفة');
            }
        });
        static::deleting(fn (self $order) => $order->assertDraft());
    }

    public function assertDraft(): void
    {
        if ($this->exists && static::whereKey($this->id)->whereNotNull('closed_at')->exists()) {
            throw new ManufacturingException('لا يمكن تعديل أمر تصنيع مكتمل');
        }
    }

    /** @return BelongsTo<ManufacturingRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ManufacturingRecipe::class, 'manufacturing_recipe_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<ManufacturingOrderInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(ManufacturingOrderInput::class);
    }

    /** @return HasMany<ManufacturingOrderOutput, $this> */
    public function outputs(): HasMany
    {
        return $this->hasMany(ManufacturingOrderOutput::class);
    }
}
