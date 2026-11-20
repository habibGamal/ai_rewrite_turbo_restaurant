<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    protected array $tables = [
        'orders',
        'order_items',
        'order_returns',
        'shifts',
        'expenses',
        'expence_types',
        'purchase_invoices',
        'return_purchase_invoices',
        'wastes',
        'stocktakings',
        'products',
        'categories',
        'suppliers',
        'users',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->char('uuid', 36)->nullable()->after('id');
                });
            }
        }

        $this->backfill();

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                $table->unique('uuid');
            });
        }
    }

    /**
     * Backfill uuids for existing records that don't have one.
     */
    public function backfill(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            DB::table($table)
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['uuid' => (string) Str::orderedUuid()]);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropUnique(['uuid']);
                }
                $table->dropColumn('uuid');
            });
        }
    }
};
