<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_items', 'inventory_item_movements', 'inventory_item_movement_daily'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            });
        }

        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['warehouse_id', 'product_id', 'created_at'], 'inventory_movements_warehouse_product_created_index');
        });

        Schema::table('inventory_item_movement_daily', function (Blueprint $table) {
            foreach ($this->newQuantityColumns() as $column) {
                $table->decimal($column, 12, 3)->default(0);
            }
        });

        $warehouseId = DB::table('warehouses')->where('is_default', true)->sole()->id;
        foreach (['inventory_items', 'inventory_item_movements', 'inventory_item_movement_daily'] as $name) {
            DB::table($name)->update(['warehouse_id' => $warehouseId]);
        }

        DB::table('inventory_item_movements')->orderBy('id')->chunkById(500, function ($movements) {
            $costs = DB::table('products')->whereIn('id', $movements->pluck('product_id'))->pluck('cost', 'id');
            foreach ($movements as $movement) {
                $cost = (float) ($costs[$movement->product_id] ?? 0);
                DB::table('inventory_item_movements')->where('id', $movement->id)->update([
                    'unit_cost' => $cost,
                    'total_cost' => round($movement->quantity * $cost, 2),
                ]);
            }
        });

        foreach (['inventory_items', 'inventory_item_movements', 'inventory_item_movement_daily'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
            });
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->index('product_id', 'inventory_items_product_lookup_index');
        });
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
            $table->unique(['product_id', 'warehouse_id']);
        });
        Schema::table('inventory_item_movement_daily', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'date']);
            $table->unique(['product_id', 'warehouse_id', 'date'], 'inventory_daily_product_warehouse_date_unique');
        });
    }

    public function down(): void
    {
        $this->mergeRows('inventory_items', ['product_id'], ['quantity']);
        $this->mergeRows('inventory_item_movement_daily', ['product_id', 'date'], [
            'start_quantity', 'incoming_quantity', 'return_sales_quantity', 'sales_quantity', 'return_waste_quantity', 'end_quantity',
        ]);

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'warehouse_id']);
            $table->unique('product_id');
        });
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex('inventory_items_product_lookup_index');
        });
        Schema::table('inventory_item_movement_daily', function (Blueprint $table) {
            $table->dropUnique('inventory_daily_product_warehouse_date_unique');
            $table->unique(['product_id', 'date']);
            $table->dropColumn($this->newQuantityColumns());
        });
        Schema::table('inventory_item_movements', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex('inventory_movements_warehouse_product_created_index');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['unit_cost', 'total_cost']);
        });
        foreach (['inventory_item_movements', 'inventory_item_movement_daily', 'inventory_items'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'inventory_item_movements') {
                    $table->dropColumn('warehouse_id');

                    return;
                }
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }
    }

    private function newQuantityColumns(): array
    {
        return ['transfer_in_quantity', 'transfer_out_quantity', 'manufacturing_in_quantity', 'manufacturing_out_quantity', 'issue_quantity'];
    }

    private function mergeRows(string $table, array $keys, array $quantities): void
    {
        $duplicates = DB::table($table)->select($keys)->groupBy($keys)->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $duplicate) {
            $query = DB::table($table)->where((array) $duplicate);
            $rows = (clone $query)->orderBy('id')->get();
            $totals = [];
            foreach ($quantities as $column) {
                $totals[$column] = $rows->sum($column);
            }
            DB::table($table)->where('id', $rows->first()->id)->update($totals);
            $query->where('id', '!=', $rows->first()->id)->delete();
        }
    }
};
