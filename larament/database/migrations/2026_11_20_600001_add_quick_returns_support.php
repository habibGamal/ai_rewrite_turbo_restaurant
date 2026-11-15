<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quick returns: a per-line "back to stock" flag (false = damaged), the
 * optional max age setting, and the dedicated return permissions.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['order.return', 'order.return_without_restock', 'order.return_any_age', 'view_returns_report'];

    public function up(): void
    {
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->boolean('restocked')->default(true)->after('refund_amount');
        });

        // The sale made in the same step when a return is an exchange.
        Schema::table('order_returns', function (Blueprint $table) {
            $table->foreignId('exchange_order_id')->nullable()->after('reverse_stock')->constrained('orders')->nullOnDelete();
        });

        // Returns saved before this column existed followed the header's switch.
        DB::table('order_return_items')
            ->whereIn('order_return_id', DB::table('order_returns')->select('id')->where('reverse_stock', false))
            ->update(['restocked' => false]);

        Setting::firstOrCreate(['key' => 'returns_max_days'], ['value' => '0']);

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Whoever could return from the POS before keeps doing so; admin holds everything.
        $returners = Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'create_order_returns'))
            ->get();
        foreach ($returners as $role) {
            $role->givePermissionTo(['order.return', 'order.return_any_age']);
        }
        Role::query()->where('name', 'admin')->first()?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('order_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_order_id');
        });
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropColumn('restocked');
        });

        Setting::query()->where('key', 'returns_max_days')->delete();
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
