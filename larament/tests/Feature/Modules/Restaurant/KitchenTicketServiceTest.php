<?php

use App\Enums\KitchenItemKind;
use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Modules\Restaurant\Events\KitchenTicketUpdated;
use App\Modules\Restaurant\Exceptions\KitchenException;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\KitchenTicketRound;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Modules\Restaurant\Services\KitchenRoutingService;
use App\Modules\Restaurant\Services\KitchenTicketService;
use App\Services\Orders\OrderService;
use App\Services\SettingsService;
use App\Services\WebApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(KitchenTicketService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('creates a ticket, a round and original lines on the first sync', function () {
    $station = KitchenStation::factory()->create();
    $product = kdsProduct([
        'name' => 'برجر',
        'kitchen_station_id' => $station->id,
        'prep_minutes' => 12,
    ]);
    $order = kdsOrder($product, 2, 'بدون بصل');

    $ticket = $this->service->syncOrder($order);

    expect($ticket)->toBeInstanceOf(KitchenTicket::class)
        ->and($ticket->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($ticket->first_sent_at)->not->toBeNull()
        ->and($ticket->rounds)->toHaveCount(1)
        ->and($ticket->rounds->first()->number)->toBe(1)
        ->and($ticket->rounds->first()->ended_at)->toBeNull();

    $line = $ticket->items()->first();
    expect($line->kind)->toBe(KitchenItemKind::ORIGINAL)
        ->and($line->status)->toBe(KitchenItemStatus::PENDING)
        ->and((float) $line->quantity)->toEqual(2.0)
        ->and((float) $line->original_quantity)->toEqual(2.0)
        ->and($line->product_name)->toBe('برجر')
        ->and($line->kitchen_station_id)->toBe($station->id)
        ->and($line->target_minutes)->toBe(12)
        ->and($line->notes)->toBe('بدون بصل')
        ->and($line->sent_at)->not->toBeNull()
        ->and($line->needs_ack)->toBeFalse();
});

it('sums order item rows of the same product and ignores the order item id', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 1);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'notes' => null,
        'price' => 10,
        'cost' => 4,
        'total' => 20,
    ]);

    $ticket = $this->service->syncOrder($order->fresh());

    expect($ticket->items)->toHaveCount(1)
        ->and((float) $ticket->items->first()->quantity)->toEqual(3.0);

    $firstItemId = $order->items()->first()->id;
    $order->items()->delete();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'notes' => null,
        'price' => 10,
        'cost' => 4,
        'total' => 30,
    ]);

    expect($order->items()->first()->id)->not->toBe($firstItemId);

    $this->service->syncOrder($order->fresh());

    expect(KitchenTicketItem::query()->count())->toBe(1);
});

it('adds an increased line when a pending item grows', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 1);
    $this->service->syncOrder($order);
    replaceItems($order, $product, 3);

    $this->service->syncOrder($order->fresh());

    $lines = KitchenTicketItem::query()->orderBy('id')->get();
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->kind)->toBe(KitchenItemKind::ORIGINAL)
        ->and((float) $lines[0]->quantity)->toEqual(1.0)
        ->and($lines[0]->status)->toBe(KitchenItemStatus::PENDING)
        ->and($lines[1]->kind)->toBe(KitchenItemKind::INCREASED)
        ->and((float) $lines[1]->quantity)->toEqual(2.0)
        ->and($lines[1]->kitchen_ticket_round_id)->toBe($lines[0]->kitchen_ticket_round_id);
});

it('keeps a done line and adds a new line when quantity grows after it is done', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 2);
    $ticket = $this->service->syncOrder($order);
    $done = $ticket->items()->first();
    $this->service->finishItem($done, $this->user);
    replaceItems($order, $product, 3);

    $this->service->syncOrder($order->fresh());

    $done->refresh();
    $added = KitchenTicketItem::query()->where('status', KitchenItemStatus::PENDING)->first();
    $ticket->refresh();

    expect($done->status)->toBe(KitchenItemStatus::DONE)
        ->and((float) $done->quantity)->toEqual(2.0)
        ->and($added)->not->toBeNull()
        ->and($added->kind)->toBe(KitchenItemKind::INCREASED)
        ->and((float) $added->quantity)->toEqual(1.0)
        ->and($added->round->number)->toBe(2)
        ->and($ticket->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($ticket->done_at)->toBeNull();
});

it('keeps original quantity and flags needs_ack when a pending line shrinks', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 3);
    $ticket = $this->service->syncOrder($order);
    replaceItems($order, $product, 2);

    $this->service->syncOrder($order->fresh());

    $line = $ticket->items()->first();
    expect((float) $line->quantity)->toEqual(2.0)
        ->and((float) $line->original_quantity)->toEqual(3.0)
        ->and($line->needs_ack)->toBeTrue()
        ->and($line->status)->toBe(KitchenItemStatus::PENDING)
        ->and($line->voided_at)->toBeNull();
});

it('voids a done line with voided_after_done when the decrease exceeds pending quantity', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 2);
    $ticket = $this->service->syncOrder($order);
    $this->service->finishItem($ticket->items()->first(), $this->user);
    replaceItems($order, $product, 3);
    $this->service->syncOrder($order->fresh());
    replaceItems($order, $product, 0);
    $order->items()->delete();

    $this->service->syncOrder($order->fresh());

    $voidedAfterDone = KitchenTicketItem::query()->where('voided_after_done', true)->get();
    $voidedPending = KitchenTicketItem::query()
        ->where('status', KitchenItemStatus::VOIDED)
        ->where('voided_after_done', false)
        ->get();

    expect($voidedAfterDone)->toHaveCount(1)
        ->and((float) $voidedAfterDone->first()->quantity)->toEqual(2.0)
        ->and($voidedAfterDone->first()->voided_at)->not->toBeNull()
        ->and($voidedAfterDone->first()->needs_ack)->toBeTrue()
        ->and($voidedPending)->toHaveCount(1)
        ->and($voidedPending->first()->needs_ack)->toBeTrue();
});

it('splits a done line when only part of a decimal quantity is removed', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 2.5);
    $ticket = $this->service->syncOrder($order);
    $done = $ticket->items()->first();
    $this->service->finishItem($done, $this->user);
    replaceItems($order, $product, 1);

    $this->service->syncOrder($order->fresh());

    $done->refresh();
    $waste = KitchenTicketItem::query()->where('voided_after_done', true)->first();

    expect($done->status)->toBe(KitchenItemStatus::DONE)
        ->and((float) $done->quantity)->toEqual(1.0)
        ->and($waste)->not->toBeNull()
        ->and((float) $waste->quantity)->toEqual(1.5)
        ->and($waste->status)->toBe(KitchenItemStatus::VOIDED)
        ->and($waste->needs_ack)->toBeTrue()
        ->and($waste->kitchen_ticket_round_id)->toBe($done->kitchen_ticket_round_id);
});

it('voids a removed product and closes an idle ticket', function () {
    $kept = kdsProduct(['name' => 'يبقى']);
    $removed = kdsProduct(['name' => 'يلغى']);
    $order = kdsOrder($kept, 1);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $removed->id,
        'quantity' => 1,
        'notes' => null,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);
    $ticket = $this->service->syncOrder($order->fresh());
    $order->items()->where('product_id', $removed->id)->delete();

    $this->service->syncOrder($order->fresh());

    $removedLine = KitchenTicketItem::query()->where('product_id', $removed->id)->first();
    $keptLine = KitchenTicketItem::query()->where('product_id', $kept->id)->first();

    expect($removedLine->status)->toBe(KitchenItemStatus::VOIDED)
        ->and($removedLine->voided_after_done)->toBeFalse()
        ->and($removedLine->needs_ack)->toBeTrue()
        ->and($keptLine->status)->toBe(KitchenItemStatus::PENDING)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::ACTIVE);

    $order->items()->delete();
    $this->service->syncOrder($order->fresh());

    expect($keptLine->fresh()->status)->toBe(KitchenItemStatus::VOIDED)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::DONE)
        ->and($ticket->fresh()->done_at)->not->toBeNull()
        ->and($ticket->rounds()->first()->ended_at)->not->toBeNull();
});

it('updates notes on open lines and only flags done lines', function () {
    $product = kdsProduct();
    $order = kdsOrder($product, 1, 'بدون بصل');
    $ticket = $this->service->syncOrder($order);
    replaceItems($order, $product, 1, 'جبنة زيادة');

    $this->service->syncOrder($order->fresh());

    $line = $ticket->items()->first();
    expect($line->notes)->toBe('جبنة زيادة')
        ->and($line->needs_ack)->toBeTrue();

    $this->service->finishItem($line->fresh(), $this->user);
    replaceItems($order, $product, 1, 'ملاحظة بعد الإنهاء');
    $this->service->syncOrder($order->fresh());

    $line->refresh();
    expect($line->status)->toBe(KitchenItemStatus::DONE)
        ->and($line->notes)->toBe('جبنة زيادة')
        ->and($line->needs_ack)->toBeTrue();
});

it('ignores products that are hidden on the kds', function () {
    $hiddenProduct = kdsProduct(['show_on_kds' => false]);
    $hiddenByCategory = kdsProduct([], ['show_on_kds' => false]);
    $shownDespiteCategory = kdsProduct(['show_on_kds' => true], ['show_on_kds' => false]);

    $order = kdsOrder($hiddenProduct, 2);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $hiddenByCategory->id,
        'quantity' => 1,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);

    expect($this->service->syncOrder($order->fresh()))->toBeNull()
        ->and(KitchenTicket::query()->count())->toBe(0);

    $visible = kdsOrder($shownDespiteCategory, 1);
    $ticket = $this->service->syncOrder($visible);

    expect($ticket->items)->toHaveCount(1)
        ->and($ticket->items->first()->product_id)->toBe($shownDespiteCategory->id);
});

it('routes a product station override ahead of the category station', function () {
    $grill = KitchenStation::factory()->create(['name' => 'شواية']);
    $pastry = KitchenStation::factory()->create(['name' => 'حلويات']);
    $category = Category::factory()->create([
        'kitchen_station_id' => $grill->id,
        'prep_minutes' => 8,
    ]);
    $inherited = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'ستيك',
    ]);
    $override = Product::factory()->create([
        'category_id' => $category->id,
        'name' => 'كنافة',
        'kitchen_station_id' => $pastry->id,
        'prep_minutes' => 20,
    ]);

    $order = kdsOrder($inherited, 1);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $override->id,
        'quantity' => 1,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);

    $this->service->syncOrder($order->fresh());

    expect(KitchenTicketItem::query()->where('product_id', $inherited->id)->value('kitchen_station_id'))->toBe($grill->id)
        ->and(KitchenTicketItem::query()->where('product_id', $inherited->id)->value('target_minutes'))->toBe(8)
        ->and(KitchenTicketItem::query()->where('product_id', $override->id)->value('kitchen_station_id'))->toBe($pastry->id)
        ->and(KitchenTicketItem::query()->where('product_id', $override->id)->value('target_minutes'))->toBe(20);
});

it('falls back from product to category to order type to the default target', function () {
    $routing = app(KitchenRoutingService::class);
    $category = Category::factory()->create(['prep_minutes' => 8]);
    $product = Product::factory()->create([
        'category_id' => $category->id,
        'prep_minutes' => null,
    ]);
    $bare = Product::factory()->create([
        'category_id' => Category::factory()->create(['prep_minutes' => null])->id,
        'prep_minutes' => null,
    ]);

    app(SettingsService::class)->set(SettingKey::KDS_ORDER_TYPE_TARGETS->value, json_encode([
        'delivery' => 25,
    ]));
    app(SettingsService::class)->set(SettingKey::KDS_DEFAULT_PREP_MINUTES->value, '10');

    expect($routing->targetMinutes($product, 'dine_in'))->toBe(8)
        ->and($routing->targetMinutes($bare, 'delivery'))->toBe(25)
        ->and($routing->targetMinutes($bare, 'takeaway'))->toBe(10);

    $order = Order::factory()->create([
        'status' => OrderStatus::PROCESSING,
        'type' => 'delivery',
        'dine_table_number' => null,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $bare->id,
        'quantity' => 1,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);

    $ticket = $this->service->syncOrder($order);

    expect($ticket->items->first()->target_minutes)->toBe(25);
});

it('counts only active rounds so an idle gap is not late kitchen time', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00'));
    $burger = kdsProduct(['name' => 'برجر', 'prep_minutes' => 15]);
    $dessert = kdsProduct(['name' => 'حلو', 'prep_minutes' => 5]);
    $order = kdsOrder($burger, 1);

    $ticket = $this->service->syncOrder($order);
    Carbon::setTestNow(Carbon::parse('2026-11-08 10:12:00'));
    $this->service->finishItem($ticket->items()->first(), $this->user);

    $ticket = $ticket->fresh(['rounds', 'items']);
    expect($ticket->status)->toBe(KitchenTicketStatus::DONE)
        ->and($this->service->closedRoundsSeconds($ticket))->toBe(12 * 60)
        ->and($this->service->openRoundTargetMinutes($ticket))->toBeNull()
        ->and($ticket->rounds->first()->ended_at->equalTo(Carbon::parse('2026-11-08 10:12:00')))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-08 10:40:00'));
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $dessert->id,
        'quantity' => 1,
        'notes' => null,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);
    $ticket = $this->service->syncOrder($order->fresh())->fresh(['rounds', 'items']);

    expect($ticket->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($this->service->closedRoundsSeconds($ticket))->toBe(12 * 60)
        ->and($this->service->openRoundTargetMinutes($ticket))->toBe(5)
        ->and($ticket->rounds)->toHaveCount(2)
        ->and($ticket->rounds->sortBy('number')->last()->started_at->equalTo(Carbon::parse('2026-11-08 10:40:00')))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-11-08 10:46:00'));
    $dessertLine = $ticket->items->firstWhere('product_id', $dessert->id);
    $this->service->finishItem($dessertLine, $this->user);

    $ticket = $ticket->fresh(['rounds']);
    expect($this->service->closedRoundsSeconds($ticket))->toBe((12 * 60) + (6 * 60))
        ->and($ticket->rounds->sortBy('number')->last()->ended_at->equalTo(Carbon::parse('2026-11-08 10:46:00')))->toBeTrue();
});

it('reopens a closed round when an item is undone inside the recall window', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 11:00:00'));
    $product = kdsProduct();
    $order = kdsOrder($product, 1);
    $ticket = $this->service->syncOrder($order);
    $item = $this->service->finishItem($ticket->items()->first(), $this->user);

    expect($ticket->fresh()->status)->toBe(KitchenTicketStatus::DONE)
        ->and($ticket->rounds()->first()->ended_at)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-11-08 11:10:00'));
    $item = $this->service->undoItem($item);

    $round = KitchenTicketRound::query()->find($item->kitchen_ticket_round_id);
    expect($item->status)->toBe(KitchenItemStatus::PENDING)
        ->and($item->done_at)->toBeNull()
        ->and($item->done_by)->toBeNull()
        ->and($round->ended_at)->toBeNull()
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($ticket->fresh()->done_at)->toBeNull();
});

it('closes every open round when an undo reopened an earlier round', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00'));
    $burger = kdsProduct(['name' => 'برجر']);
    $dessert = kdsProduct(['name' => 'حلو']);
    $order = kdsOrder($burger, 1);
    $ticket = $this->service->syncOrder($order);
    $burgerLine = $this->service->finishItem($ticket->items()->first(), $this->user);

    Carbon::setTestNow(Carbon::parse('2026-11-08 12:05:00'));
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $dessert->id,
        'quantity' => 1,
        'price' => $dessert->price,
        'cost' => $dessert->cost,
        'total' => $dessert->price,
    ]);
    $ticket = $this->service->syncOrder($order->fresh());
    $this->service->undoItem($burgerLine);

    expect(KitchenTicketRound::query()->where('kitchen_ticket_id', $ticket->id)->whereNull('ended_at')->count())->toBe(2);

    Carbon::setTestNow(Carbon::parse('2026-11-08 12:08:00'));
    foreach ($ticket->items()->where('status', '!=', KitchenItemStatus::DONE->value)->get() as $line) {
        $this->service->finishItem($line, $this->user);
    }

    expect(KitchenTicketRound::query()->where('kitchen_ticket_id', $ticket->id)->whereNull('ended_at')->count())->toBe(0)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::DONE);
});

it('rejects undo after the recall window', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 11:00:00'));
    $order = kdsOrder(kdsProduct(), 1);
    $ticket = $this->service->syncOrder($order);
    $item = $this->service->finishItem($ticket->items()->first(), $this->user);
    Carbon::setTestNow(Carbon::parse('2026-11-08 11:16:00'));

    expect(fn () => $this->service->undoItem($item->fresh()))
        ->toThrow(KitchenException::class);
    expect($item->fresh()->status)->toBe(KitchenItemStatus::DONE);
});

it('cancels a ticket, voids done lines as waste, and waits for acknowledgement', function () {
    $order = kdsOrder(kdsProduct(), 1);
    $ticket = $this->service->syncOrder($order);
    $this->service->finishItem($ticket->items()->first(), $this->user);
    replaceItems($order, kdsProduct(), 1);
    $this->service->syncOrder($order->fresh());

    $cancelled = $this->service->cancelForOrder($order);

    expect($cancelled->status)->toBe(KitchenTicketStatus::CANCELLED)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancel_acknowledged_at)->toBeNull()
        ->and(KitchenTicketItem::query()->where('status', '!=', KitchenItemStatus::VOIDED)->count())->toBe(0)
        ->and(KitchenTicketItem::query()->where('voided_after_done', true)->exists())->toBeTrue();

    $this->service->acknowledge($cancelled);

    expect($cancelled->fresh()->cancel_acknowledged_at)->not->toBeNull()
        ->and($cancelled->fresh()->seen_at)->not->toBeNull()
        ->and(KitchenTicketItem::query()->where('needs_ack', true)->count())->toBe(0);
});

it('auto-finishes open lines only when the setting is on and flags them', function () {
    $order = kdsOrder(kdsProduct(), 1);
    $ticket = $this->service->syncOrder($order);
    $this->service->finishItem($ticket->items()->first(), $this->user);
    replaceItems($order, Product::query()->find($order->items()->first()->product_id), 2);
    $this->service->syncOrder($order->fresh());

    $finished = $this->service->autoFinishForOrder($order->fresh());
    $auto = KitchenTicketItem::query()->where('auto_finished', true)->first();
    $cooked = KitchenTicketItem::query()->where('auto_finished', false)->where('status', KitchenItemStatus::DONE)->first();

    expect($finished->status)->toBe(KitchenTicketStatus::DONE)
        ->and($finished->auto_finished)->toBeTrue()
        ->and($auto->done_by)->toBeNull()
        ->and($auto->status)->toBe(KitchenItemStatus::DONE)
        ->and($cooked->done_by)->toBe($this->user->id)
        ->and($cooked->auto_finished)->toBeFalse();

    app(SettingsService::class)->set(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value, 'false');
    $other = kdsOrder(kdsProduct(), 1);
    $open = $this->service->syncOrder($other);
    $untouched = $this->service->autoFinishForOrder($other);

    expect($untouched->status)->toBe(KitchenTicketStatus::ACTIVE)
        ->and($untouched->auto_finished)->toBeFalse()
        ->and($open->items()->first()->status)->toBe(KitchenItemStatus::PENDING);
});

it('rejects item bumps when item tracking is off and still allows finishing the ticket', function () {
    app(SettingsService::class)->set(SettingKey::KDS_ITEM_TRACKING->value, 'false');
    $order = kdsOrder(kdsProduct(), 1);
    $ticket = $this->service->syncOrder($order);
    $item = $ticket->items()->first();

    expect(fn () => $this->service->finishItem($item, $this->user))->toThrow(KitchenException::class);

    $done = $this->service->finishTicket($ticket, null, $this->user);

    expect($done->status)->toBe(KitchenTicketStatus::DONE)
        ->and($item->fresh()->status)->toBe(KitchenItemStatus::DONE)
        ->and($item->fresh()->done_by)->toBe($this->user->id)
        ->and($item->fresh()->auto_finished)->toBeFalse();
});

it('does not sync a pending web order and syncs it when the order is accepted', function () {
    Http::fake();
    $order = kdsOrder(kdsProduct(), 1, null, ['status' => OrderStatus::PENDING]);

    expect($this->service->syncOrder($order))->toBeNull()
        ->and(KitchenTicket::query()->count())->toBe(0);

    app(WebApiService::class)->acceptOrder($order->id);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::PROCESSING)
        ->and($order->kitchenTicket)->not->toBeNull()
        ->and($order->kitchenTicket->items)->toHaveCount(1)
        ->and($order->kitchenTicket->items->first()->kind)->toBe(KitchenItemKind::ORIGINAL);
});

it('dispatches KitchenTicketUpdated after a real change and skips a no-op sync', function () {
    Event::fake([KitchenTicketUpdated::class]);
    $station = KitchenStation::factory()->create();
    $product = kdsProduct(['kitchen_station_id' => $station->id]);
    $order = kdsOrder($product, 1);

    $ticket = $this->service->syncOrder($order);

    Event::assertDispatched(KitchenTicketUpdated::class, function (KitchenTicketUpdated $event) use ($ticket, $order, $station): bool {
        $payload = $event->broadcastWith();

        return $event->broadcastAs() === 'KitchenTicketUpdated'
            && $event->broadcastOn()[0]->name === 'private-kitchen'
            && $payload === [
                'ticket_id' => $ticket->id,
                'order_number' => (int) $order->order_number,
                'reason' => 'new',
                'station_ids' => [$station->id],
            ];
    });

    $this->service->syncOrder($order->fresh());
    Event::assertDispatchedTimes(KitchenTicketUpdated::class, 1);

    replaceItems($order, $product, 2);
    $this->service->syncOrder($order->fresh());

    Event::assertDispatched(KitchenTicketUpdated::class, fn (KitchenTicketUpdated $event): bool => $event->reason === 'changed');
});

it('hooks order edits, cancellation, completion and type changes', function () {
    Http::fake();
    Event::fake([KitchenTicketUpdated::class]);

    $product = kdsProduct(['type' => 'manufactured']);
    $order = Order::factory()->create([
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
        'dine_table_number' => null,
    ]);

    $updated = app(OrderService::class)->updateOrderItems($order->id, [[
        'product_id' => $product->id,
        'quantity' => 1,
    ]]);

    expect($updated->kitchenTicket)->not->toBeNull()
        ->and($updated->kitchenTicket->items)->toHaveCount(1);

    app(OrderService::class)->changeOrderType($order->id, 'delivery');
    Event::assertDispatched(
        KitchenTicketUpdated::class,
        fn (KitchenTicketUpdated $event): bool => $event->reason === KitchenTicketUpdated::REASON_CHANGED,
    );

    $completed = app(OrderService::class)->completeOrder($order->id, [
        'cash' => (float) $updated->fresh()->total,
    ]);
    expect($completed->status)->toBe(OrderStatus::COMPLETED)
        ->and($completed->kitchenTicket->fresh()->auto_finished)->toBeTrue()
        ->and($completed->kitchenTicket->status)->toBe(KitchenTicketStatus::DONE);

    $web = kdsOrder(kdsProduct(), 1);
    $this->service->syncOrder($web);
    app(WebApiService::class)->rejectOrder($web->id);
    expect($web->fresh()->kitchenTicket->status)->toBe(KitchenTicketStatus::CANCELLED);

    $other = kdsOrder(kdsProduct(), 1);
    $this->service->syncOrder($other);
    app(WebApiService::class)->completeOrder($other->id);
    expect($other->fresh()->kitchenTicket->auto_finished)->toBeTrue();

    $cancelled = kdsOrder(kdsProduct(), 1);
    $this->service->syncOrder($cancelled);
    app(OrderService::class)->cancelOrder($cancelled->id);
    expect($cancelled->fresh()->status)->toBe(OrderStatus::CANCELLED)
        ->and($cancelled->fresh()->kitchenTicket->status)->toBe(KitchenTicketStatus::CANCELLED);
});

function kdsProduct(array $product = [], array $category = []): Product
{
    $categoryModel = Category::factory()->create($category);

    return Product::factory()->create(array_merge([
        'category_id' => $categoryModel->id,
        'name' => 'صنف',
        'type' => 'manufactured',
        'price' => 25,
        'cost' => 10,
    ], $product));
}

function kdsOrder(Product $product, float $quantity, ?string $notes = null, array $order = []): Order
{
    $orderModel = Order::factory()->create(array_merge([
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
        'dine_table_number' => null,
    ], $order));

    OrderItem::factory()->create([
        'order_id' => $orderModel->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'notes' => $notes,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * $quantity,
    ]);

    return $orderModel->fresh('items');
}

function replaceItems(Order $order, Product $product, float $quantity, ?string $notes = null): void
{
    $order->items()->delete();
    if ($quantity <= 0) {
        return;
    }

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'notes' => $notes,
        'price' => $product->price,
        'cost' => $product->cost,
        'total' => $product->price * $quantity,
    ]);
}
