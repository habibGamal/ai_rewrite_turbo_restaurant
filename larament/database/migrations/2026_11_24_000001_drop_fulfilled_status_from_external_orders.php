<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Outsourced orders no longer track «تم التنفيذ»: a line is either active
 * (pending) or cancelled with its host order. Fulfilled rows become active.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('external_orders')->where('status', 'fulfilled')->update(['status' => 'pending']);
    }

    public function down(): void
    {
        // Nothing to restore: which rows were fulfilled is not kept.
    }
};
