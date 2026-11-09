<?php

use App\Enums\KitchenItemStatus;
use App\Enums\KitchenTicketStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SettingKey;
use App\Models\Category;
use App\Models\Customer;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\Kitchen\KitchenTicketService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

it('forbids the kitchen screen without kitchen.access', function () {
    $cashier = User::factory()->cashier()->create();
    $order = kitchenOrder();

    $this->actingAs($cashier)
        ->get(route('kitchen.index'))
        ->assertForbidden();

    $this->actingAs($cashier)
        ->post(route('kitchen.items.done', $order['item']))
        ->assertForbidden();
});

it('renders index props that match the kds contract and are not shift bound', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00'));
    $kitchen = User::factory()->kitchen()->create();
    $customer = Customer::factory()->create(['name' => 'أحمد']);
    $station = KitchenStation::factory()->create(['name' => 'شواية', 'is_active' => true, 'sort_order' => 1]);
    KitchenStation::factory()->create(['name' => 'مغلقة', 'is_active' => false, 'sort_order' => 0]);
    $later = KitchenStation::factory()->create(['name' => 'مشاوي', 'is_active' => true, 'sort_order' => 2]);

    $product = Product::factory()->create([
        'category_id' => Category::factory()->create(['kitchen_station_id' => $station->id])->id,
        'name' => 'برجر',
        'prep_minutes' => 12,
        'type' => 'manufactured',
    ]);
    $closedShift = Shift::factory()->closed()->create();
    $order = Order::factory()->create([
        'status' => OrderStatus::PROCESSING,
        'type' => OrderType::DINE_IN,
        'shift_id' => $closedShift->id,
        'customer_id' => $customer->id,
        'dine_table_number' => '12',
        'kitchen_notes' => 'بدون بصل',
        'order_number' => 41,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'notes' => 'ميديم',
        'price' => 25,
        'cost' => 10,
        'total' => 25,
    ]);
    $ticket = app(KitchenTicketService::class)->syncOrder($order);

    $this->actingAs($kitchen)
        ->get(route('kitchen.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Kitchen/Index')
            ->missing('orders')
            ->where('hasActiveShift', false)
            ->where('serverNow', fn (string $value): bool => str_contains($value, 'T'))
            ->has('stations', 2)
            ->where('stations.0', ['id' => $station->id, 'name' => 'شواية'])
            ->where('stations.1', ['id' => $later->id, 'name' => 'مشاوي'])
            ->has('kdsSettings', fn (Assert $settings) => $settings
                ->where('item_tracking', true)
                ->where('warning_percent', 75)
                ->where('recall_minutes', 15)
                ->where('new_order_sound', 'chime')
                ->where('change_sound', 'bell')
                ->where('cancel_sound', 'alert')
                ->where('sound_volume', 80)
                ->where('reminder_seconds', 30)
            )
            ->has('tickets', 1)
            ->has('tickets.0', fn (Assert $card) => $card
                ->where('id', $ticket->id)
                ->where('order_id', $order->id)
                ->where('order_number', 41)
                ->where('order_type', 'dine_in')
                ->where('order_type_label', OrderType::DINE_IN->label())
                ->where('table_number', '12')
                ->where('customer_name', 'أحمد')
                ->where('kitchen_notes', 'بدون بصل')
                ->where('status', 'active')
                ->where('is_rush', false)
                ->where('first_sent_at', fn (string $value): bool => str_contains($value, 'T'))
                ->where('done_at', null)
                ->where('cancelled_at', null)
                ->where('seen_at', null)
                ->where('cancel_acknowledged_at', null)
                ->where('auto_finished', false)
                ->where('closed_rounds_seconds', 0)
                ->where('open_round_target_minutes', 12)
                ->has('rounds', 1)
                ->has('items', 1)
                ->has('rounds.0', fn (Assert $round) => $round
                    ->where('id', $ticket->rounds()->first()->id)
                    ->where('number', 1)
                    ->where('started_at', fn (string $value): bool => str_contains($value, 'T'))
                    ->where('ended_at', null)
                )
                ->has('items.0', fn (Assert $item) => $item
                    ->where('id', $ticket->items()->first()->id)
                    ->where('round_id', $ticket->rounds()->first()->id)
                    ->where('product_id', $product->id)
                    ->where('product_name', 'برجر')
                    ->where('station_id', $station->id)
                    ->where('quantity', 1)
                    ->where('original_quantity', 1)
                    ->where('notes', 'ميديم')
                    ->where('kind', 'original')
                    ->where('status', 'pending')
                    ->where('target_minutes', 12)
                    ->where('sent_at', fn (string $value): bool => str_contains($value, 'T'))
                    ->where('done_at', null)
                    ->where('voided_at', null)
                    ->where('voided_after_done', false)
                    ->where('needs_ack', false)
                    ->where('auto_finished', false)
                )
            )
        );

    Shift::factory()->active()->create();

    $this->actingAs($kitchen)
        ->get(route('kitchen.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasActiveShift', true)
            ->has('tickets', 1)
            ->where('tickets.0.id', $ticket->id)
        );
});

it('includes recallable done tickets and unacknowledged cancellations only', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00'));
    $kitchen = User::factory()->kitchen()->create();
    $service = app(KitchenTicketService::class);

    $active = $service->syncOrder(kitchenOrder()['order']);

    $recent = $service->syncOrder(kitchenOrder()['order']);
    $service->finishTicket($recent, null, $kitchen);
    $recent->refresh();
    $recent->done_at = Carbon::parse('2026-11-08 12:10:00');
    $recent->save();

    Carbon::setTestNow(Carbon::parse('2026-11-08 12:20:00'));
    $stale = $service->syncOrder(kitchenOrder()['order']);
    $service->finishTicket($stale, null, $kitchen);
    $stale->refresh();
    $stale->done_at = Carbon::parse('2026-11-08 11:40:00');
    $stale->save();

    $cancelled = $service->syncOrder(kitchenOrder()['order']);
    $service->cancelForOrder($cancelled->order);
    $acked = $service->syncOrder(kitchenOrder()['order']);
    $service->cancelForOrder($acked->order);
    $service->acknowledge($acked->fresh());

    $this->actingAs($kitchen)
        ->get(route('kitchen.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tickets', 3)
            ->where('tickets', function ($tickets) use ($active, $recent, $stale, $acked): bool {
                $ids = collect($tickets)->pluck('id')->all();

                return in_array($active->id, $ids, true)
                    && in_array($recent->id, $ids, true)
                    && ! in_array($stale->id, $ids, true)
                    && ! in_array($acked->id, $ids, true)
                    && collect($tickets)->contains(fn ($ticket): bool => $ticket['status'] === 'cancelled');
            })
        );
});

it('bumps, undoes, finishes, acknowledges and rushes through the kitchen routes', function () {
    $kitchen = User::factory()->kitchen()->create();
    $cashier = User::factory()->cashier()->create();
    $viewer = User::factory()->viewer()->create();
    $built = kitchenOrder();
    $item = $built['item'];
    $ticket = $built['ticket'];

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.items.done', $item))
        ->assertRedirect(route('kitchen.index'));
    expect($item->fresh()->status)->toBe(KitchenItemStatus::DONE)
        ->and($item->fresh()->done_by)->toBe($kitchen->id)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::DONE);

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.items.undo', $item))
        ->assertRedirect(route('kitchen.index'));
    expect($item->fresh()->status)->toBe(KitchenItemStatus::PENDING)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::ACTIVE);

    $grill = KitchenStation::factory()->create();
    $other = KitchenTicketItem::factory()->create([
        'kitchen_ticket_id' => $ticket->id,
        'kitchen_ticket_round_id' => $ticket->rounds()->first()->id,
        'kitchen_station_id' => $grill->id,
        'status' => KitchenItemStatus::PENDING,
    ]);

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.done', $ticket), ['station_id' => $grill->id])
        ->assertRedirect(route('kitchen.index'));
    expect($other->fresh()->status)->toBe(KitchenItemStatus::DONE)
        ->and($item->fresh()->status)->toBe(KitchenItemStatus::PENDING)
        ->and($ticket->fresh()->status)->toBe(KitchenTicketStatus::ACTIVE);

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.done', $ticket), ['station_id' => 99999])
        ->assertSessionHasErrors('station_id');

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.done', $ticket))
        ->assertRedirect(route('kitchen.index'));
    expect($ticket->fresh()->status)->toBe(KitchenTicketStatus::DONE);

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.items.undo', $item))
        ->assertRedirect(route('kitchen.index'));
    $item->refresh();
    $item->needs_ack = true;
    $item->save();

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.acknowledge', $ticket))
        ->assertRedirect(route('kitchen.index'));
    expect($ticket->fresh()->seen_at)->not->toBeNull()
        ->and($item->fresh()->needs_ack)->toBeFalse();

    $this->actingAs($cashier)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.rush', $ticket), ['is_rush' => true])
        ->assertRedirect(route('kitchen.index'));
    expect($ticket->fresh()->is_rush)->toBeTrue();

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.rush', $ticket), ['is_rush' => false])
        ->assertRedirect(route('kitchen.index'));
    expect($ticket->fresh()->is_rush)->toBeFalse();

    $this->actingAs($viewer)
        ->post(route('kitchen.tickets.rush', $ticket), ['is_rush' => true])
        ->assertForbidden();

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.rush', $ticket), [])
        ->assertSessionHasErrors('is_rush');
});

it('translates item-tracking and recall errors back to the screen', function () {
    $kitchen = User::factory()->kitchen()->create();
    $built = kitchenOrder();
    app(SettingsService::class)->set(SettingKey::KDS_ITEM_TRACKING->value, 'false');

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.items.done', $built['item']))
        ->assertSessionHasErrors('kitchen');

    app(SettingsService::class)->set(SettingKey::KDS_ITEM_TRACKING->value, 'true');
    $this->actingAs($kitchen)->post(route('kitchen.items.done', $built['item']));
    Carbon::setTestNow(now()->addMinutes(16));

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.items.undo', $built['item']))
        ->assertRedirect(route('kitchen.index'))
        ->assertSessionHasErrors('kitchen');
});

it('acknowledges a cancelled ticket from the kitchen route', function () {
    $kitchen = User::factory()->kitchen()->create();
    $built = kitchenOrder();
    app(KitchenTicketService::class)->cancelForOrder($built['order']);

    $this->actingAs($kitchen)
        ->from(route('kitchen.index'))
        ->post(route('kitchen.tickets.acknowledge', $built['ticket']))
        ->assertRedirect(route('kitchen.index'));

    expect($built['ticket']->fresh()->cancel_acknowledged_at)->not->toBeNull()
        ->and($built['ticket']->fresh()->status)->toBe(KitchenTicketStatus::CANCELLED);
});

it('backfills processing orders once and stays idempotent', function () {
    $open = kitchenOrder(sync: false);
    $pending = Order::factory()->create(['status' => OrderStatus::PENDING]);
    OrderItem::factory()->create([
        'order_id' => $pending->id,
        'product_id' => $open['product']->id,
        'quantity' => 1,
        'price' => 10,
        'cost' => 4,
        'total' => 10,
    ]);
    $empty = Order::factory()->create(['status' => OrderStatus::PROCESSING]);
    $existing = kitchenOrder();
    $before = KitchenTicketItem::query()->count();

    $this->artisan('kitchen:backfill-open-orders')->assertSuccessful();

    expect(KitchenTicket::query()->where('order_id', $open['order']->id)->exists())->toBeTrue()
        ->and(KitchenTicket::query()->where('order_id', $pending->id)->exists())->toBeFalse()
        ->and(KitchenTicket::query()->where('order_id', $empty->id)->exists())->toBeFalse()
        ->and(KitchenTicket::query()->count())->toBe(2)
        ->and(KitchenTicketItem::query()->count())->toBe($before + 1);

    $this->artisan('kitchen:backfill-open-orders')->assertSuccessful();

    expect(KitchenTicket::query()->count())->toBe(2)
        ->and(KitchenTicketItem::query()->count())->toBe($before + 1);
});

/**
 * @return array{order: Order, product: Product, ticket: KitchenTicket|null, item: KitchenTicketItem|null}
 */
function kitchenOrder(bool $sync = true): array
{
    $product = Product::factory()->create([
        'category_id' => Category::factory()->create()->id,
        'name' => 'صنف',
        'type' => 'manufactured',
        'price' => 20,
        'cost' => 8,
    ]);
    $order = Order::factory()->create([
        'status' => OrderStatus::PROCESSING,
        'type' => OrderType::TAKEAWAY,
        'dine_table_number' => null,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'notes' => null,
        'price' => 20,
        'cost' => 8,
        'total' => 20,
    ]);

    $ticket = null;
    $item = null;
    if ($sync) {
        $ticket = app(KitchenTicketService::class)->syncOrder($order->fresh());
        $item = $ticket?->items()->first();
    }

    return [
        'order' => $order->fresh(),
        'product' => $product,
        'ticket' => $ticket,
        'item' => $item,
    ];
}

it('does not expose the removed item start route', function () {
    $kitchen = User::factory()->kitchen()->create();
    $item = kitchenOrder()['item'];

    $this->actingAs($kitchen)->post("/kitchen/items/{$item->id}/start")->assertNotFound();
});
