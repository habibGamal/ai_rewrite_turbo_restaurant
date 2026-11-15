<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_changes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('order_id')->constrained('users')->nullOnDelete();
            $table->decimal('unit_price', 10, 2)->default(0)->after('delta');
            $table->decimal('total_price', 10, 2)->default(0)->after('unit_price');

            $table->index(['user_id', 'created_at']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                UPDATE order_item_changes
                SET user_id = (SELECT user_id FROM orders WHERE orders.id = order_item_changes.order_id)
                WHERE user_id IS NULL
            ');

            DB::statement('
                UPDATE order_item_changes
                SET unit_price = COALESCE((SELECT price FROM products WHERE products.id = order_item_changes.product_id), 0),
                    total_price = ABS(delta) * COALESCE((SELECT price FROM products WHERE products.id = order_item_changes.product_id), 0)
                WHERE unit_price = 0
            ');
        } else {
            // Backfill user_id from order creator for existing rows
            DB::statement('
                UPDATE order_item_changes oic
                JOIN orders o ON o.id = oic.order_id
                SET oic.user_id = o.user_id
                WHERE oic.user_id IS NULL AND o.user_id IS NOT NULL
            ');

            // Backfill unit_price from products
            DB::statement('
                UPDATE order_item_changes oic
                JOIN products p ON p.id = oic.product_id
                SET oic.unit_price = p.price,
                    oic.total_price = ABS(oic.delta) * p.price
                WHERE oic.unit_price = 0 AND p.price IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('order_item_changes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn(['user_id', 'unit_price', 'total_price']);
        });
    }
};
