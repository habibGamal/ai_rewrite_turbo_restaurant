<?php

namespace App\Models;

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Enums\SettingKey;
use App\Models\Concerns\HasSyncUuid;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\OrderDisplay;
use App\Orders\OrderTypeCatalog;
use App\Terminals\TerminalSessionResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory, HasSyncUuid;

    protected $fillable = [
        'uuid',
        'customer_id',
        'driver_id',
        'user_id',
        'shift_id',
        'terminal_id',
        'type',
        'status',
        'sub_total',
        'tax',
        'service',
        'discount',
        'temp_discount_percent',
        'total',
        'profit',
        'web_pos_diff',
        'fees_total',
        'equipment_total',
        'equipment_cost',
        'guests_count',
        'payment_status',
        'return_status',
        'dine_table_number',
        'kitchen_notes',
        'order_notes',
        'order_number',
        'web_order_key',
        'web_preferences',
    ];

    protected $appends = [
        'service_rate',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'return_status' => ReturnStatus::class,
        'sub_total' => 'decimal:2',
        'tax' => 'decimal:2',
        'service' => 'decimal:2',
        'discount' => 'decimal:2',
        'temp_discount_percent' => 'decimal:2',
        'total' => 'decimal:2',
        'profit' => 'decimal:2',
        'fees_total' => 'decimal:2',
        'equipment_total' => 'decimal:2',
        'equipment_cost' => 'decimal:2',
        'guests_count' => 'integer',
        'web_preferences' => 'array',
    ];

    public function getServiceRateAttribute(): float
    {
        if ($this->display()->chargesService) {
            return (float) setting(SettingKey::DINE_IN_SERVICE_CHARGE);
        }

        return 0.0;
    }

    public function behavior(): OrderTypeBehavior
    {
        return app(OrderTypeCatalog::class)->behaviorFor((string) $this->type);
    }

    public function typeRow(): OrderTypeRow
    {
        return app(OrderTypeCatalog::class)->find((string) $this->type);
    }

    public function display(): OrderDisplay
    {
        return app(OrderTypeCatalog::class)->display((string) $this->type);
    }

    public function getOrderTypeLabel(): string
    {
        return $this->display()->label;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        // Remember which drawer the order was opened from.
        static::creating(function (Order $order): void {
            if ($order->terminal_id === null && $order->getAttribute('shift_id') !== null) {
                $order->terminal_id = app(TerminalSessionResolver::class)->forShift((int) $order->shift_id)?->terminal_id;
            }
        });
    }

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function kitchenTicket(): HasOne
    {
        return $this->hasOne(KitchenTicket::class);
    }

    /** @return HasManyThrough<KitchenTicketItem, KitchenTicket, $this> */
    public function kitchenItems(): HasManyThrough
    {
        return $this->hasManyThrough(KitchenTicketItem::class, KitchenTicket::class);
    }

    /** @return HasMany<ExternalOrder, $this> */
    /**
     * Custom fee lines («رسوم إضافية»); their sum is fees_total.
     */
    public function fees(): HasMany
    {
        return $this->hasMany(OrderFee::class)->orderBy('id');
    }

    public function externalOrders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class, 'host_order_id');
    }

    /**
     * @return HasMany<OrderEquipment, $this>
     */
    public function equipmentLines(): HasMany
    {
        return $this->hasMany(OrderEquipment::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function table(): HasOne
    {
        return $this->hasOne(DineTable::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function itemChanges(): HasMany
    {
        return $this->hasMany(OrderItemChange::class)->orderBy('created_at', 'desc');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderHistory::class)->orderBy('created_at', 'desc');
    }

    // Computed attributes
    public function getTypeStringAttribute(): string
    {
        return $this->getOrderTypeLabel();
    }

    public function getStatusStringAttribute(): string
    {
        return $this->status->label();
    }

    public function getPaymentStatusStringAttribute(): string
    {
        return $this->payment_status->label();
    }

    public function getTotalPaidAttribute(): float
    {
        return $this->payments->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, $this->total - $this->total_paid);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->payment_status->isFullyPaid();
    }

    public function getCanBeModifiedAttribute(): bool
    {
        return $this->status->canBeModified();
    }

    public function getCanBeCancelledAttribute(): bool
    {
        return $this->status->canBeCancelled();
    }

    public function getTotalRefundedAttribute(): float
    {
        return $this->returns->sum('total_refund');
    }

    public function getReturnStatusStringAttribute(): string
    {
        return $this->return_status ? $this->return_status->label() : ReturnStatus::NONE->label();
    }

    public function getKitchenStatus(): ?string
    {
        if (setting(SettingKey::KDS_CASHIER_STATUS_ENABLED) !== 'true') {
            return null;
        }

        /** @var KitchenTicket|null $ticket */
        $ticket = $this->relationLoaded('kitchenTicket')
            ? $this->kitchenTicket
            : $this->kitchenTicket()->first();

        if ($ticket === null) {
            return null;
        }

        return match ($ticket->status) {
            KitchenTicketStatus::DONE => 'ready',
            KitchenTicketStatus::ACTIVE => 'in_progress',
            default => null,
        };
    }

    public function getItemKitchenStatus(int $productId): ?string
    {
        if (setting(SettingKey::KDS_CASHIER_STATUS_ENABLED) !== 'true') {
            return null;
        }

        /** @var KitchenTicket|null $ticket */
        $ticket = $this->relationLoaded('kitchenTicket')
            ? $this->kitchenTicket
            : $this->kitchenTicket()->with('items')->first();

        if ($ticket === null) {
            return null;
        }

        /** @var Collection<int, KitchenTicketItem> $items */
        $items = $ticket->relationLoaded('items')
            ? $ticket->items
            : $ticket->items()->get();

        $kitchenItems = $items
            ->where('product_id', $productId)
            ->where('status', '!=', KitchenItemStatus::VOIDED);

        if ($kitchenItems->isEmpty()) {
            return null;
        }

        $allDone = $kitchenItems->every(fn (KitchenTicketItem $item): bool => $item->status === KitchenItemStatus::DONE);

        return $allDone ? 'ready' : 'in_progress';
    }

    /**
     * @return array{done: int, total: int, status: string}|null
     */
    public function getKitchenProgress(): ?array
    {
        if (setting(SettingKey::KDS_CASHIER_STATUS_ENABLED) !== 'true') {
            return null;
        }

        /** @var KitchenTicket|null $ticket */
        $ticket = $this->relationLoaded('kitchenTicket')
            ? $this->kitchenTicket
            : $this->kitchenTicket()->with('items')->first();

        if ($ticket === null || $ticket->status === KitchenTicketStatus::CANCELLED) {
            return null;
        }

        /** @var Collection<int, KitchenTicketItem> $items */
        $items = $ticket->relationLoaded('items')
            ? $ticket->items
            : $ticket->items()->get();

        $activeItems = $items->where('status', '!=', KitchenItemStatus::VOIDED);
        $total = $activeItems->count();

        if ($total === 0) {
            return null;
        }

        $done = $activeItems->where('status', KitchenItemStatus::DONE)->count();

        if ($ticket->status === KitchenTicketStatus::DONE) {
            $done = $total;
        }

        return [
            'done' => $done,
            'total' => $total,
            'status' => $ticket->status === KitchenTicketStatus::DONE ? 'ready' : 'in_progress',
        ];
    }
}
