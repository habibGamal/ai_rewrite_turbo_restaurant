<?php

/*
 * The website's /api/order-status requires its integration token as a Bearer
 * token; the POS keeps it in Settings and sends it with every status update.
 */

use App\Enums\SettingKey;
use App\Models\Order;
use App\Modules\WebOrders\Services\WebApiService;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    app(SettingsService::class)->set(SettingKey::WEBSITE_URL->value, 'https://site.test');
    SafetyNet::product(price: 80, cost: 30, stock: 20, attributes: ['product_ref' => 'BURGER']);
    Http::fake(['*' => Http::response(['message' => 'ok'])]);

    app(WebApiService::class)->placeOrder([
        'user' => ['name' => 'Web Guest', 'phone' => '01011112222', 'area' => 'Nasr City', 'address' => '12 Street'],
        'order' => [
            'type' => 'web_delivery', 'shiftId' => $this->shift->id, 'orderNumber' => 'ORD-TOKEN',
            'subTotal' => 80, 'tax' => 0, 'service' => 0, 'discount' => 0, 'total' => 80,
            'items' => [['quantity' => 1, 'notes' => '', 'posRefObj' => [['productRef' => 'BURGER', 'quantity' => 1]]]],
        ],
    ]);
});

it('sends the website integration token as a bearer token on status updates', function () {
    app(SettingsService::class)->set(SettingKey::WEBSITE_INTEGRATION_TOKEN->value, 'site-integration-token-123456');

    app(WebApiService::class)->acceptOrder(Order::sole()->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://site.test/api/order-status'
        && $request->hasHeader('Authorization', 'Bearer site-integration-token-123456')
        && $request['orderNumber'] === 'ORD-TOKEN'
        && $request['status'] === 'processing');
});

it('sends no authorization header when no token is saved', function () {
    app(WebApiService::class)->acceptOrder(Order::sole()->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://site.test/api/order-status'
        && ! $request->hasHeader('Authorization'));
});

it('accepts an empty or a 16+ character token without spaces', function () {
    expect(SettingKey::WEBSITE_INTEGRATION_TOKEN->validate('short'))->toBeFalse()
        ->and(SettingKey::WEBSITE_INTEGRATION_TOKEN->validate(str_repeat('a', 48)))->toBeTrue()
        ->and(SettingKey::WEBSITE_INTEGRATION_TOKEN->validate(''))->toBeTrue();
});
