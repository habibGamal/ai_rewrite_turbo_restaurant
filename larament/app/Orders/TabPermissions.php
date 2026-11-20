<?php

namespace App\Orders;

use App\Models\OrderTypeRow;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Maps cashier-desk tab keys (see CashierTabLayout) to Spatie permissions and
 * keeps the permission rows in sync with the order types that exist.
 */
class TabPermissions
{
    public const GROUP = 'تبويبات الكاشير';

    public const TYPE_PREFIX = 'pos.tab.type.';

    public const SYSTEM_PREFIX = 'pos.tab.system.';

    public const GUARD = 'web';

    public static function forKey(string $tabKey): string
    {
        if (str_starts_with($tabKey, 'type:') && strlen($tabKey) > 5) {
            return self::forTypeCode(substr($tabKey, 5));
        }

        if (str_starts_with($tabKey, 'system:') && strlen($tabKey) > 7) {
            return self::SYSTEM_PREFIX.substr($tabKey, 7);
        }

        throw new InvalidArgumentException("Unknown cashier tab key [{$tabKey}].");
    }

    public static function forTypeCode(string $code): string
    {
        return self::TYPE_PREFIX.$code;
    }

    /**
     * Permission name => Arabic label for every order type row and system tab.
     *
     * @return array<string, string>
     */
    public function catalog(): array
    {
        if (! Schema::hasTable('order_types')) {
            return [];
        }

        $catalog = [];

        foreach (OrderTypeRow::query()->orderBy('id')->get() as $row) {
            $catalog[self::forTypeCode($row->code)] = 'تبويب: '.$row->label;
        }

        foreach (CashierTabLayout::SYSTEM_TABS as $name => $system) {
            $catalog[self::SYSTEM_PREFIX.$name] = 'تبويب: '.$system['label'];
        }

        return $catalog;
    }

    /**
     * Create missing catalog permissions and delete orphaned type permissions.
     *
     * @return list<string> names of the permissions that were created
     */
    public function sync(): array
    {
        if (! Schema::hasTable('order_types')) {
            return [];
        }

        $catalog = $this->catalog();
        $created = [];

        foreach (array_keys($catalog) as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);

            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        Permission::query()
            ->where('guard_name', self::GUARD)
            ->where('name', 'like', self::TYPE_PREFIX.'%')
            ->whereNotIn('name', array_keys($catalog))
            ->get()
            ->each(fn (Permission $permission) => $permission->delete());

        $this->resetCache();

        return $created;
    }

    /**
     * Give permissions to admin and to every role that holds pos.access.
     *
     * @param  list<string>  $permissionNames
     */
    public function grantToPosRoles(array $permissionNames): void
    {
        if ($permissionNames === []) {
            return;
        }

        $roles = Role::query()
            ->where('guard_name', self::GUARD)
            ->where(function ($query): void {
                $query->where('name', 'admin')
                    ->orWhereHas('permissions', fn ($permissions) => $permissions->where('name', 'pos.access'));
            })
            ->get();

        foreach ($roles as $role) {
            $role->givePermissionTo($permissionNames);
        }

        $this->resetCache();
    }

    public function ensureForType(OrderTypeRow $row): void
    {
        $name = self::forTypeCode($row->code);
        $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => self::GUARD]);

        if ($permission->wasRecentlyCreated) {
            $this->grantToPosRoles([$name]);
        }

        $this->resetCache();
    }

    public function renameForType(string $oldCode, OrderTypeRow $row): void
    {
        $old = Permission::query()
            ->where('name', self::forTypeCode($oldCode))
            ->where('guard_name', self::GUARD)
            ->first();

        $newName = self::forTypeCode($row->code);

        if ($old instanceof Permission && ! Permission::query()->where('name', $newName)->where('guard_name', self::GUARD)->exists()) {
            $old->update(['name' => $newName]);
            $this->resetCache();

            return;
        }

        $this->ensureForType($row);
    }

    public function removeForType(OrderTypeRow $row): void
    {
        Permission::query()
            ->where('name', self::forTypeCode($row->code))
            ->where('guard_name', self::GUARD)
            ->get()
            ->each(fn (Permission $permission) => $permission->delete());

        $this->resetCache();
    }

    private function resetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
