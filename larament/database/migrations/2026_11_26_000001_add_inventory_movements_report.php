<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * «تقرير حركة المخزون» replaces the purchases, waste, stocktaking, stock
 * issues, transfers and movement summary reports. Roles that could open any
 * of them can open the new one; the old permissions go away with the pages.
 */
return new class extends Migration
{
    private const PERMISSION = 'view_inventory_movements_report';

    private const REPLACED = [
        'view_purchases_report',
        'view_waste_report',
        'view_stocktaking_report',
        'view_stock_issues_report',
        'view_transfers_report',
        'view_movement_summary_report',
    ];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->whereIn('name', self::REPLACED))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
        Role::query()->where('name', 'admin')->first()?->givePermissionTo($permission);

        Permission::query()->whereIn('name', self::REPLACED)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roles = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', self::PERMISSION))
            ->get();

        foreach (self::REPLACED as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $roles->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
        Permission::query()->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
