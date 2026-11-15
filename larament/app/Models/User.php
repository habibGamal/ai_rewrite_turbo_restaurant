<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrderStatus;
use App\Models\Concerns\HasSyncUuid;
use App\Orders\TabPermissions;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, HasSyncUuid, Notifiable, SoftDeletes;
    use HasRoles {
        hasPermissionTo as spatieHasPermissionTo;
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = is_string($permission)
            ? $permission
            : ($permission instanceof \Spatie\Permission\Contracts\Permission ? $permission->name : null);

        if ($permissionName !== null && app(\App\Services\FeatureService::class)->isPermissionDisabled($permissionName)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('access_admin_panel');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isViewer(): bool
    {
        return $this->hasRole('viewer');
    }

    public function canManageOrders(): bool
    {
        return $this->can('pos.access');
    }

    public function canCancelOrders(): bool
    {
        return $this->can('order.cancel');
    }

    public function canApplyDiscounts(): bool
    {
        return $this->can('order.apply_discount');
    }

    public function canChangeOrderItems(): bool
    {
        return $this->can('order.change_items');
    }

    /**
     * Whether this user may use a cashier-desk tab (`type:{code}` or `system:{name}`).
     */
    public function canSeeTab(string $tabKey): bool
    {
        return $this->can(TabPermissions::forKey($tabKey));
    }

    /**
     * Whether this user may open an order: its type tab is allowed, or it is a
     * completed / cancelled order and the matching system tab is allowed.
     */
    public function canAccessOrder(Order $order): bool
    {
        if ($this->canSeeTab('type:'.$order->type)) {
            return true;
        }

        return match ($order->status) {
            OrderStatus::COMPLETED => $this->canSeeTab('system:completed'),
            OrderStatus::CANCELLED => $this->canSeeTab('system:cancelled'),
            default => false,
        };
    }

    public function canAccessKitchen(): bool
    {
        return $this->can('kitchen.access');
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function purchaseInvoices()
    {
        return $this->hasMany(PurchaseInvoice::class);
    }

    public function returnPurchaseInvoices()
    {
        return $this->hasMany(ReturnPurchaseInvoice::class);
    }

    public function wastes()
    {
        return $this->hasMany(Waste::class);
    }

    public function stocktaking()
    {
        return $this->hasMany(Stocktaking::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id');
    }

    public function itemChanges(): HasMany
    {
        return $this->hasMany(OrderItemChange::class, 'user_id');
    }
}
