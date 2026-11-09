<?php

use App\Enums\KdsSound;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\Concerns\HasSyncUuid;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

it('migrates the kitchen foundation tables', function () {
    expect(Schema::hasTable('kitchen_stations'))->toBeTrue()
        ->and(Schema::hasTable('kitchen_tickets'))->toBeTrue()
        ->and(Schema::hasTable('kitchen_ticket_rounds'))->toBeTrue()
        ->and(Schema::hasTable('kitchen_ticket_items'))->toBeTrue()
        ->and(Schema::hasColumns('kitchen_stations', ['name', 'is_active', 'sort_order']))->toBeTrue()
        ->and(Schema::hasColumns('categories', ['kitchen_station_id', 'show_on_kds', 'prep_minutes']))->toBeTrue()
        ->and(Schema::hasColumns('products', ['kitchen_station_id', 'show_on_kds', 'prep_minutes']))->toBeTrue()
        ->and(Schema::hasColumns('kitchen_tickets', [
            'order_id', 'status', 'is_rush', 'first_sent_at', 'done_at', 'cancelled_at',
            'seen_at', 'cancel_acknowledged_at', 'auto_finished',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('kitchen_ticket_rounds', [
            'kitchen_ticket_id', 'number', 'started_at', 'ended_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('kitchen_ticket_items', [
            'kitchen_ticket_id', 'kitchen_ticket_round_id', 'product_id', 'product_name',
            'kitchen_station_id', 'quantity', 'original_quantity', 'notes', 'kind', 'status',
            'target_minutes', 'sent_at', 'done_at', 'voided_at', 'done_by',
            'voided_after_done', 'needs_ack', 'auto_finished',
        ]))->toBeTrue();
});

it('reverses category and product kitchen columns', function () {
    $categories = require database_path('migrations/2026_11_08_000002_add_kitchen_fields_to_categories_table.php');
    $products = require database_path('migrations/2026_11_08_000003_add_kitchen_fields_to_products_table.php');

    try {
        $products->down();
        $categories->down();

        expect(Schema::hasColumn('categories', 'kitchen_station_id'))->toBeFalse()
            ->and(Schema::hasColumn('categories', 'show_on_kds'))->toBeFalse()
            ->and(Schema::hasColumn('products', 'kitchen_station_id'))->toBeFalse()
            ->and(Schema::hasColumn('products', 'show_on_kds'))->toBeFalse();
    } finally {
        if (! Schema::hasColumn('categories', 'kitchen_station_id')) {
            $categories->up();
        }

        if (! Schema::hasColumn('products', 'kitchen_station_id')) {
            $products->up();
        }
    }
});

it('builds a station ticket round item graph from factories', function () {
    $station = KitchenStation::factory()->create(['name' => 'شواية']);
    $item = KitchenTicketItem::factory()->create([
        'kitchen_station_id' => $station->id,
    ]);

    expect($item->kitchenTicket)->toBeInstanceOf(KitchenTicket::class)
        ->and($item->kitchenTicket->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($item->kitchenTicket->first_sent_at)->not->toBeNull()
        ->and($item->round)->toBeInstanceOf(KitchenTicketRound::class)
        ->and($item->round->kitchen_ticket_id)->toBe($item->kitchen_ticket_id)
        ->and($item->station->is($station))->toBeTrue()
        ->and($item->kitchenTicket->rounds)->toHaveCount(1)
        ->and($item->round->items)->toHaveCount(1);
});

it('resolves kitchen relations and keeps product show_on_kds null', function () {
    $station = KitchenStation::factory()->create();
    $category = Category::factory()->create(['kitchen_station_id' => $station->id]);
    $category->refresh();
    $product = Product::factory()->create([
        'category_id' => $category->id,
    ]);
    $product->refresh();

    expect($category->show_on_kds)->toBeTrue()
        ->and($product->show_on_kds)->toBeNull()
        ->and($category->kitchenStation->is($station))->toBeTrue()
        ->and($product->kitchenStation)->toBeNull();

    $routedProduct = Product::factory()->create([
        'category_id' => $category->id,
        'kitchen_station_id' => $station->id,
    ]);

    $order = Order::factory()->create();
    $ticket = KitchenTicket::factory()->create(['order_id' => $order->id]);
    KitchenTicketRound::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'ended_at' => now(),
    ]);
    $open = KitchenTicketRound::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'ended_at' => null,
    ]);
    $item = KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $open->id,
        'kitchen_station_id' => $station->id,
        'product_id' => $routedProduct->id,
    ]);

    expect($order->kitchenTicket->is($ticket))->toBeTrue()
        ->and($ticket->openRound->is($open))->toBeTrue()
        ->and($item->round->is($open))->toBeTrue()
        ->and($item->station->is($station))->toBeTrue()
        ->and($routedProduct->kitchenStation->is($station))->toBeTrue()
        ->and($station->categories)->toHaveCount(1)
        ->and($station->products)->toHaveCount(1);
});

it('orders active stations and applies item factory states', function () {
    KitchenStation::factory()->create(['name' => 'Later', 'is_active' => true, 'sort_order' => 5]);
    KitchenStation::factory()->create(['name' => 'First', 'is_active' => true, 'sort_order' => 1]);
    KitchenStation::factory()->create(['name' => 'Off', 'is_active' => false, 'sort_order' => 0]);

    expect(KitchenStation::query()->active()->pluck('name')->all())->toBe(['First', 'Later']);

    $done = KitchenTicketItem::factory()->done()->create();
    $voided = KitchenTicketItem::factory()->voided()->create();

    expect($done->status)->toBe(KitchenItemStatus::DONE)
        ->and($done->done_at)->not->toBeNull()
        ->and($done->doneBy)->not->toBeNull()
        ->and($voided->status)->toBe(KitchenItemStatus::VOIDED)
        ->and($voided->voided_at)->not->toBeNull()
        ->and(KitchenItemStatus::PENDING->isOpen())->toBeTrue()
        ->and(KitchenItemStatus::DONE->isOpen())->toBeFalse()
        ->and(KitchenItemStatus::VOIDED->isOpen())->toBeFalse();
});

it('keeps kitchen models out of the sync outbox trait', function () {
    expect(class_uses_recursive(KitchenStation::class))->not->toContain(HasSyncUuid::class)
        ->and(class_uses_recursive(KitchenTicket::class))->not->toContain(HasSyncUuid::class)
        ->and(class_uses_recursive(KitchenTicketRound::class))->not->toContain(HasSyncUuid::class)
        ->and(class_uses_recursive(KitchenTicketItem::class))->not->toContain(HasSyncUuid::class);
});

it('accepts every new kds setting default under its validation rules', function () {
    $keys = [
        SettingKey::KDS_ITEM_TRACKING,
        SettingKey::KDS_DEFAULT_PREP_MINUTES,
        SettingKey::KDS_ORDER_TYPE_TARGETS,
        SettingKey::KDS_WARNING_PERCENT,
        SettingKey::KDS_AUTO_FINISH_ON_COMPLETE,
        SettingKey::KDS_RECALL_MINUTES,
        SettingKey::KDS_NEW_ORDER_SOUND,
        SettingKey::KDS_CHANGE_SOUND,
        SettingKey::KDS_CANCEL_SOUND,
        SettingKey::KDS_SOUND_VOLUME,
        SettingKey::KDS_REMINDER_SECONDS,
    ];

    foreach ($keys as $key) {
        $validator = Validator::make(
            [$key->value => $key->defaultValue()],
            [$key->value => $key->validationRules()],
        );

        expect($validator->passes())->toBeTrue($key->value.': '.implode(' ', $validator->errors()->all()))
            ->and($key->validate($key->defaultValue()))->toBeTrue()
            ->and($key->label())->not->toBe('')
            ->and($key->helperText())->not->toBe('')
            ->and($key->placeholder())->not->toBe('');

        expect(Setting::query()->where('key', $key->value)->value('value'))->toBe($key->defaultValue());
    }

    expect(SettingKey::KDS_NEW_ORDER_SOUND->defaultValue())->toBe(KdsSound::CHIME->value)
        ->and(SettingKey::KDS_CHANGE_SOUND->defaultValue())->toBe(KdsSound::BELL->value)
        ->and(SettingKey::KDS_CANCEL_SOUND->defaultValue())->toBe(KdsSound::ALERT->value);
});
