<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Models\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenceType extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'name',
        'category',
        'avg_month_rate',
    ];

    protected $attributes = [
        'category' => 'other',
    ];

    protected $casts = [
        'category' => ExpenseCategory::class,
        'avg_month_rate' => 'decimal:2',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
