<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    protected array $tables = [
        'shifts',
        'wastes',
        'purchase_invoices',
        'return_purchase_invoices',
        'stocktakings',
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

            if (! Schema::hasColumn($table, 'business_date')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->date('business_date')->nullable();
                });
            }
        }

        $this->backfill();
    }

    /**
     * Backfill business_date from start_at / closed_at for existing rows.
     */
    public function backfill(): void
    {
        if (Schema::hasTable('shifts') && Schema::hasColumn('shifts', 'business_date')) {
            DB::table('shifts')
                ->whereNull('business_date')
                ->whereNotNull('start_at')
                ->orderBy('id')
                ->chunkById(100, function ($shifts) {
                    foreach ($shifts as $shift) {
                        DB::table('shifts')
                            ->where('id', $shift->id)
                            ->update([
                                'business_date' => Carbon::parse($shift->start_at)->toDateString(),
                            ]);
                    }
                });
        }

        $closedTables = ['wastes', 'purchase_invoices', 'return_purchase_invoices', 'stocktakings'];
        foreach ($closedTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_date')) {
                continue;
            }

            DB::table($table)
                ->whereNull('business_date')
                ->whereNotNull('closed_at')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([
                                'business_date' => Carbon::parse($row->closed_at)->toDateString(),
                            ]);
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
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'business_date')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('business_date');
            });
        }
    }
};
