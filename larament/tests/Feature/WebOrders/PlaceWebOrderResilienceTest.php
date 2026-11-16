<?php

/*
 * A web order must be accepted even when Reverb is down, and a retry of an
 * order the POS already saved must be answered as "already received" (only
 * when it truly is the same order) instead of a duplicate-key 400.
 */

use App\Broadcasting\ResilientBroadcaster;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    config(['app.web_api_key' => 'resilience-key']);

    SafetyNet::product(price: 80, cost: 30, stock: 20, attributes: ['product_ref' => 'BURGER']);
    SafetyNet::product(price: 20, cost: 5, stock: 20, attributes: ['product_ref' => 'FRIES']);
});

function resilienceWebOrder(int $shiftId, array $overrides = []): array
{
    return array_replace_recursive([
        'user' => ['name' => 'Web Guest', 'phone' => '01011112222', 'area' => 'Nasr City', 'address' => '12 Street'],
        'order' => [
            'type' => 'web_delivery',
            'shiftId' => $shiftId,
            'orderNumber' => 'ORD-RMWP0QN1',
            'idempotencyKey' => 'site-key-160',
            'subTotal' => 200,
            'tax' => 0,
            'service' => 15,
            'discount' => 0,
            'total' => 210,
            'items' => [
                ['quantity' => 2, 'notes' => '', 'posRefObj' => [
                    ['productRef' => 'BURGER', 'quantity' => 1],
                    ['productRef' => 'FRIES', 'quantity' => 1],
                ]],
            ],
        ],
    ], $overrides);
}

function resiliencePlace(array $payload): TestResponse
{
    return test()->withHeaders(['X-Api-Key' => 'resilience-key'])->postJson('/api/web-orders/place-order', $payload);
}

function useUnreachableReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'k',
        'broadcasting.connections.reverb.secret' => 's',
        'broadcasting.connections.reverb.app_id' => '1',
        'broadcasting.connections.reverb.options.host' => '127.0.0.1',
        'broadcasting.connections.reverb.options.port' => 1, // nothing listens here
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);
    Broadcast::purge('reverb');
}

describe('when Reverb is down', function () {
    it('still accepts the web order and saves it once', function () {
        useUnreachableReverb();
        Log::spy();

        resiliencePlace(resilienceWebOrder($this->shift->id))
            ->assertOk()
            ->assertJsonPath('already_received', false)
            ->assertJsonPath('order.status', OrderStatus::PENDING->value);

        expect(Order::count())->toBe(1)
            ->and(Order::sole()->web_order_key)->toBe('site-key-160');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Broadcast skipped'))->atLeast()->once();
    });

    it('wraps network broadcasters so a send failure never throws', function () {
        useUnreachableReverb();

        $broadcaster = Broadcast::connection('reverb');

        expect($broadcaster)->toBeInstanceOf(ResilientBroadcaster::class);
        $broadcaster->broadcast(['private-web-orders'], 'web-order.received', ['x' => 1]);
    });
});

it('swallows and logs a broadcaster failure but passes channel registration through', function () {
    $inner = Mockery::mock(Broadcaster::class);
    $inner->shouldReceive('broadcast')->andThrow(new RuntimeException('cURL error 7'));
    $inner->shouldReceive('channel')->with('web-orders', Mockery::type('callable'))->once()->andReturnSelf();
    Log::spy();

    $resilient = new ResilientBroadcaster($inner, 'reverb');
    $resilient->broadcast(['web-orders'], 'evt');
    $resilient->channel('web-orders', fn () => true);

    Log::shouldHaveReceived('warning')->once();
});

it('leaves the log and null drivers unwrapped', function () {
    expect(Broadcast::connection('log'))->not->toBeInstanceOf(ResilientBroadcaster::class)
        ->and(Broadcast::connection('null'))->not->toBeInstanceOf(ResilientBroadcaster::class);
});

describe('a retried web order', function () {
    it('is answered with the saved order instead of a duplicate error', function () {
        $first = resiliencePlace(resilienceWebOrder($this->shift->id))->assertOk();

        resiliencePlace(resilienceWebOrder($this->shift->id))
            ->assertOk()
            ->assertJsonPath('already_received', true)
            ->assertJsonPath('order.id', $first->json('order.id'))
            ->assertJsonPath('order.status', 'pending');

        expect(Order::count())->toBe(1);
    });

    it('reports the current POS status of the saved order', function () {
        resiliencePlace(resilienceWebOrder($this->shift->id))->assertOk();
        Order::sole()->update(['status' => OrderStatus::PROCESSING]);

        resiliencePlace(resilienceWebOrder($this->shift->id))
            ->assertOk()
            ->assertJsonPath('already_received', true)
            ->assertJsonPath('order.status', 'processing');
    });

    it('is still recognised after the shift that saved it was closed', function () {
        resiliencePlace(resilienceWebOrder($this->shift->id))->assertOk();
        $this->shift->update(['closed' => true]);
        $next = Shift::factory()->active()->create(['user_id' => $this->user->id]);

        resiliencePlace(resilienceWebOrder($next->id))
            ->assertOk()
            ->assertJsonPath('already_received', true);

        expect(Order::count())->toBe(1);
    });

    it('matches an older order saved without a key by number and content', function () {
        resiliencePlace(resilienceWebOrder($this->shift->id, ['order' => ['idempotencyKey' => null]]))->assertOk();

        resiliencePlace(resilienceWebOrder($this->shift->id))
            ->assertOk()
            ->assertJsonPath('already_received', true);
    });

    it('is a conflict when the key differs', function () {
        resiliencePlace(resilienceWebOrder($this->shift->id))->assertOk();

        resiliencePlace(resilienceWebOrder($this->shift->id, ['order' => ['idempotencyKey' => 'another-order']]))
            ->assertStatus(409)
            ->assertJsonPath('conflict', true)
            ->assertJsonPath('mismatches', ['مفتاح الطلب']);

        expect(Order::count())->toBe(1);
    });

    it('is a conflict when the customer, total or items differ', function (array $overrides, string $field) {
        resiliencePlace(resilienceWebOrder($this->shift->id))->assertOk();

        $response = resiliencePlace(resilienceWebOrder($this->shift->id, $overrides))->assertStatus(409);

        expect($response->json('mismatches'))->toContain($field)
            ->and(Order::count())->toBe(1);
    })->with([
        'customer' => [['user' => ['phone' => '01099998888']], 'العميل'],
        'total' => [['order' => ['total' => 250]], 'الإجمالي'],
        'items' => [['order' => ['items' => [['quantity' => 3]]]], 'الأصناف'],
    ]);
});
