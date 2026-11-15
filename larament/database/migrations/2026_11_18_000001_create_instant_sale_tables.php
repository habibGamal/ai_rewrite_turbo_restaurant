<?php

use App\Enums\SettingKey;
use App\Models\OrderTypeRow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6c: instant sale. orders.client_uuid makes the one-request sale
 * idempotent, held_sales parks a cart without creating an order, and a
 * system order type "بيع مباشر" gives the cashier its tab (active by default
 * for every business type except restaurants, which opt in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('client_uuid')->nullable()->unique();
        });

        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('order_type');
            $table->string('label')->nullable();
            $table->json('lines');
            $table->json('meta')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['shift_id', 'created_at']);
        });

        if (! DB::table('order_types')->where('code', 'instant_sale')->exists()) {
            $businessType = Schema::hasTable('settings')
                ? DB::table('settings')->where('key', SettingKey::BUSINESS_TYPE->value)->value('value')
                : null;

            // Model create so the observer adds and grants the tab permission.
            OrderTypeRow::query()->create([
                'code' => 'instant_sale',
                'label' => 'بيع مباشر',
                'color' => 'success',
                'icon' => 'heroicon-o-shopping-cart',
                'is_active' => $businessType !== null && $businessType !== 'restaurant',
                'is_system' => true,
                'behavior' => 'instant',
                'allow_partial_payment' => false,
                'allow_pay_later' => false,
                'require_customer_for_credit' => true,
            ]);
        }
    }

    public function down(): void
    {
        OrderTypeRow::query()->where('code', 'instant_sale')->get()->each->delete();

        Schema::dropIfExists('held_sales');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
