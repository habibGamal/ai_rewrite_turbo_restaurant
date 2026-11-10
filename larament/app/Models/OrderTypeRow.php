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
        'allow_partial_payment',
        'allow_pay_later',
        'require_customer_for_credit',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'behavior' => OrderBehavior::class,
        'allow_partial_payment' => 'boolean',
        'allow_pay_later' => 'boolean',
        'require_customer_for_credit' => 'boolean',
    ];

    public function allowsCredit(): bool
    {
        return (bool) ($this->allow_partial_payment || $this->allow_pay_later);
    }

    public function allowsPartialPayment(): bool
    {
        return (bool) $this->allow_partial_payment;
    }

    public function allowsPayLater(): bool
    {
        return (bool) $this->allow_pay_later;
    }

    public function requiresCustomerForCredit(): bool
    {
        return (bool) $this->require_customer_for_credit;
    }
}
