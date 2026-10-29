<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasSyncUuid;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, HasRoles, HasSyncUuid, Notifiable, SoftDeletes;

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
}
