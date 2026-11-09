<?php

use App\Models\KitchenTicketItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('converts old item statuses and removes the item start column and setting', function () {
    $migration = require database_path('migrations/2026_11_09_000001_remove_kds_start_step.php');
    $migration->down();
    $item = KitchenTicketItem::factory()->create();
    DB::table('kitchen_ticket_items')->where('id', $item->id)->update(['status' => 'started']);

    $migration->up();

    expect(DB::table('kitchen_ticket_items')->where('id', $item->id)->value('status'))->toBe('pending')
        ->and(Schema::hasColumn('kitchen_ticket_items', 'started_at'))->toBeFalse()
        ->and(DB::table('settings')->where('key', 'kds_require_start')->exists())->toBeFalse();
});
