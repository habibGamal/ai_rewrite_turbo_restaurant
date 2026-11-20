<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->uuid('branch_uuid');
            $table->text('token');
            $table->text('branch_secret');
            $table->string('timezone')->default('Africa/Cairo');
            $table->string('status', 16)->default('active');
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
