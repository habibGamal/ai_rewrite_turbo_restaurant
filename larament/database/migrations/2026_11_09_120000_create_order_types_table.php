<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('color');
            $table->string('icon');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('behavior');
            $table->timestamps();
        });

        $now = now();

        DB::table('order_types')->insert([
            [
                'code' => 'dine_in',
                'label' => 'صالة',
                'color' => 'success',
                'icon' => 'heroicon-o-home',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'dine_in',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'takeaway',
                'label' => 'تيك أواي',
                'color' => 'info',
                'icon' => 'heroicon-o-shopping-bag',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'counter',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'delivery',
                'label' => 'دليفري',
                'color' => 'danger',
                'icon' => 'heroicon-o-truck',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'delivery',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'companies',
                'label' => 'شركات',
                'color' => 'gray',
                'icon' => 'heroicon-o-building-office',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'companies',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'talabat',
                'label' => 'طلبات',
                'color' => 'warning',
                'icon' => 'heroicon-o-device-phone-mobile',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'counter',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'web_delivery',
                'label' => 'اونلاين دليفري',
                'color' => 'primary',
                'icon' => 'heroicon-o-globe-alt',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'web_delivery',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'web_takeaway',
                'label' => 'اونلاين تيك أواي',
                'color' => 'primary',
                'icon' => 'heroicon-o-computer-desktop',
                'is_active' => true,
                'is_system' => true,
                'behavior' => 'web_takeaway',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_types');
    }
};
