<?php

use App\Enums\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // (a) Merge duplicate inventory_items rows per product_id:
        // Keep the lowest id, set its quantity to the SUM of all rows for that product, delete the others
        $duplicateProductIds = DB::table('inventory_items')
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('product_id');

        foreach ($duplicateProductIds as $productId) {
            $rows = DB::table('inventory_items')
                ->where('product_id', $productId)
                ->orderBy('id', 'asc')
                ->get();

            if ($rows->count() > 1) {
                $keep = $rows->first();
                $totalQuantity = $rows->sum('quantity');
                $deleteIds = $rows->slice(1)->pluck('id');

                DB::table('inventory_items')
                    ->where('id', $keep->id)
                    ->update([
                        'quantity' => $totalQuantity,
                        'updated_at' => now(),
                    ]);

                DB::table('inventory_items')
                    ->whereIn('id', $deleteIds)
                    ->delete();
            }
        }

        // (b) Insert missing inventory_items rows (quantity 0) for every product
        // whose type is not 'manufactured' and has no row
        $existingProductIds = DB::table('inventory_items')->pluck('product_id');

        $missingProducts = DB::table('products')
            ->where('type', '!=', ProductType::Manufactured->value)
            ->whereNotIn('id', $existingProductIds)
            ->pluck('id');

        $now = now();
        $inserts = [];
        foreach ($missingProducts as $productId) {
            $inserts[] = [
                'product_id' => $productId,
                'quantity' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($inserts)) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('inventory_items')->insert($chunk);
            }
        }

        // (c) Add unique index on inventory_items.product_id
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unique('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
        });
    }
};
