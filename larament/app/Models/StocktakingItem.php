<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StocktakingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stocktaking_id',
        'product_id',
        'quantity',
    ];

    public function stocktaking()
    {
        return $this->belongsTo(Stocktaking::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
