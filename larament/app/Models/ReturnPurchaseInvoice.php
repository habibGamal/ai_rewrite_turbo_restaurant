<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnPurchaseInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'supplier_id',
        'total',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
