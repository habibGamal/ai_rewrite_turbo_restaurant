<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function movements()
    {
        return $this->hasMany(InventoryItemMovement::class, 'product_id', 'product_id');
    }

    public function usedInProducts()
    {
        return $this->hasMany(ProductComponent::class, 'component_id', 'product_id');
    }
}
