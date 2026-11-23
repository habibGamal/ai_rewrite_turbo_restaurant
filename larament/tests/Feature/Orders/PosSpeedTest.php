<?php

use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function queriesDuring(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('reads each setting once per request', function () {
    $settings = app(SettingsService::class);

    $queries = queriesDuring(function () use ($settings) {
        foreach (range(1, 50) as $ignored) {
            $settings->get(SettingKey::KDS_CASHIER_STATUS_ENABLED->value);
        }
    });

    expect($queries)->toBe(1);
});

it('sees a saved setting at once', function () {
    $settings = app(SettingsService::class);
    $key = SettingKey::RECEIPT_FOOTER->value;

    expect($settings->get($key, 'none'))->toBe('none');

    $settings->set($key, 'شكرا لزيارتكم');
    expect($settings->get($key))->toBe('شكرا لزيارتكم');

    Setting::query()->where('key', $key)->first()->update(['value' => 'تحديث']);
    expect($settings->get($key))->toBe('تحديث');
});

it('forgets remembered settings between requests', function () {
    $key = SettingKey::RECEIPT_FOOTER->value;
    app(SettingsService::class)->get($key);

    // A write that skips the model (like another server process) is seen by the next request.
    DB::table('settings')->insert(['key' => $key, 'value' => 'من طلب آخر', 'created_at' => now(), 'updated_at' => now()]);
    event(new RequestHandled(request(), response('')));

    expect(app(SettingsService::class)->get($key))->toBe('من طلب آخر');
});

describe('cashier orders screen', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->shift = Shift::factory()->create(['user_id' => $this->user->id, 'end_at' => null, 'closed' => false, 'end_cash' => null]);
        $this->actingAs($this->user);
    });

    function seedShiftOrders(Shift $shift, User $user, int $count): void
    {
        $products = Product::factory()->count(5)->create(['legacy' => false]);
        $start = (int) Order::query()->where('shift_id', $shift->id)->max('order_number');
        foreach (range($start + 1, $start + $count) as $number) {
            $order = Order::factory()->create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'type' => 'takeaway',
                'status' => OrderStatus::COMPLETED,
                'order_number' => $number,
            ]);
            foreach ($products->take(3) as $product) {
                OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
            }
        }
    }

    it('does not grow its query count with the number of orders', function () {
        seedShiftOrders($this->shift, $this->user, 5);
        $this->get(route('orders.index'))->assertOk(); // first request warms one-time lookups
        $few = queriesDuring(fn () => $this->get(route('orders.index'))->assertOk());

        seedShiftOrders($this->shift, $this->user, 30);
        $many = queriesDuring(fn () => $this->get(route('orders.index'))->assertOk());

        expect($many)->toBe($few);
    });

    it('builds only the props a partial reload asks for', function () {
        $response = $this->get(route('orders.index'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response->assertInertia(fn ($page) => $page->reloadOnly('orders', fn ($reload) => $reload
            ->has('orders')
            ->missing('tabs')
            ->missing('expenses')
            ->missing('receivables')));
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        // The expenses list and the instant-sale catalogue are not built for an orders-only reload.
        expect($sql)->not->toContain('"expenses"')
            ->and($sql)->not->toContain('"categories"');
    });

    it('returns to the completed order type tab in one redirect', function () {
        $order = Order::factory()->create([
            'shift_id' => $this->shift->id,
            'user_id' => $this->user->id,
            'type' => 'takeaway',
            'status' => OrderStatus::PROCESSING,
            'total' => 0,
            'sub_total' => 0,
        ]);

        $this->post(route('orders.complete', $order), ['cash' => 0, 'card' => 0, 'talabat_card' => 0])
            ->assertRedirect(route('orders.index', ['tab' => 'takeaway']));
    });

    it('answers a print with a short message, not a page', function () {
        $order = Order::factory()->create(['shift_id' => $this->shift->id, 'user_id' => $this->user->id]);

        $response = $this->post(route('print', $order));

        expect($response->isRedirect())->toBeFalse();
        $response->assertHeader('content-type', 'application/json')->assertJsonStructure(['message']);
    });
});
