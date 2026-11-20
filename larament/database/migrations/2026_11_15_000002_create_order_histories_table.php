<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // created, items_added, items_removed, items_modified, payment_added, completed, cancelled, discount_applied, customer_linked, driver_linked, notes_updated, return_created
            $table->string('action_name'); // Human-readable Arabic label
            $table->text('description')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Seed initial history entries for existing orders
        DB::statement("
            INSERT INTO order_histories (order_id, user_id, action, action_name, description, created_at, updated_at)
            SELECT id, user_id, 'created', 'إنشاء الطلب', CONCAT('تم إنشاء الطلب برقم #', COALESCE(order_number, id)), created_at, created_at
            FROM orders
        ");

        DB::statement("
            INSERT INTO order_histories (order_id, user_id, action, action_name, description, created_at, updated_at)
            SELECT id, user_id, 'completed', 'إتمام الطلب', 'تم إتمام الطلب بنجاح', updated_at, updated_at
            FROM orders
            WHERE status = 'completed'
        ");

        DB::statement("
            INSERT INTO order_histories (order_id, user_id, action, action_name, description, created_at, updated_at)
            SELECT id, user_id, 'cancelled', 'إلغاء الطلب', 'تم إلغاء الطلب', updated_at, updated_at
            FROM orders
            WHERE status = 'cancelled'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_histories');
    }
};
