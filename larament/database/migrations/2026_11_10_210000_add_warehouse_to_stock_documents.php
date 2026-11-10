<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            });
        }

        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');

        foreach ($this->tables() as $name) {
            DB::table($name)->whereNull('warehouse_id')->update(['warehouse_id' => $warehouseId]);

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['purchase_invoices', 'return_purchase_invoices', 'wastes', 'stocktakings'];
    }
};
