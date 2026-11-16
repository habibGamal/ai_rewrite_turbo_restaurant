<?php

use App\Enums\SettingKey;
use App\Services\BranchService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rejects protected routes without api key with 401 json', function () {
    config(['app.web_api_key' => 'secret-test-key']);

    $response = $this->getJson('/api/products/product_search');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthorized']);
});

it('rejects protected routes with wrong api key with 401 json', function () {
    config(['app.web_api_key' => 'secret-test-key']);

    $response = $this->withHeaders([
        'X-Api-Key' => 'wrong-key',
    ])->getJson('/api/products/product_search');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthorized']);
});

it('allows protected routes with correct api key', function () {
    config(['app.web_api_key' => 'secret-test-key']);

    $response = $this->withHeaders([
        'X-Api-Key' => 'secret-test-key',
    ])->getJson('/api/products/product_search');

    expect($response->status())->not->toBe(401);
});

it('rejects all protected routes when web_api_key config is empty even with empty header', function (?string $configKey) {
    config(['app.web_api_key' => $configKey]);

    $response = $this->withHeaders([
        'X-Api-Key' => '',
    ])->getJson('/api/products/product_search');

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthorized']);
})->with([
    'null config' => null,
    'empty string config' => '',
]);

it('allows /api/check without key for GET and POST', function (string $method) {
    config(['app.web_api_key' => 'secret-test-key']);

    $response = $this->json($method, '/api/check');

    $response->assertStatus(200)
        ->assertJson(['message' => 'turbo']);
})->with(['GET', 'POST']);

it('rejects management endpoints when key is provided via query parameter only', function () {
    config(['app.management_secret_key' => 'mgmt-secret-123']);

    $response = $this->getJson('/api/management/status?key=mgmt-secret-123');

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Unauthorized access',
        ]);
});

it('authorizes management endpoints when key is provided via X-Management-Key header', function () {
    config(['app.management_secret_key' => 'mgmt-secret-123']);

    $response = $this->withHeaders([
        'X-Management-Key' => 'mgmt-secret-123',
    ])->getJson('/api/management/status');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);
});

it('includes X-Api-Key header on outgoing master node requests in BranchService', function () {
    config(['app.web_api_key' => 'outgoing-secret-key']);

    Http::fake([
        'https://master.example.com/*' => Http::response(['message' => 'ok'], 200),
    ]);

    app(SettingsService::class)->set(SettingKey::MASTER_NODE_LINK->value, 'https://master.example.com');

    $branchService = app(BranchService::class);
    $branchService->testMasterConnection();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://master.example.com/api/check'
            && $request->hasHeader('X-Api-Key', 'outgoing-secret-key');
    });
});

it('protects every required api route with api key verification', function (string $method, string $uri) {
    config(['app.web_api_key' => 'secret-test-key']);

    $response = $this->json($method, $uri);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Unauthorized']);
})->with([
    ['GET', '/api/products/product_search'],
    ['POST', '/api/validate_products'],
    ['GET', '/api/all-products'],
    ['GET', '/api/all-products-refs-master'],
    ['GET', '/api/all-products-prices-master'],
    ['GET', '/api/all-products-recipes-master'],
    ['GET', '/api/get-products-master'],
    ['GET', '/api/get-products-master-by-refs'],
    ['GET', '/api/get-products-prices-master'],
    ['POST', '/api/web-orders/place-order'],
    ['GET', '/api/can-accept-order'],
    ['GET', '/api/get-shift-id'],
    ['POST', '/api/save-order/1'],
]);

it('accepts the key saved in settings and prefers it over the env key', function () {
    config(['app.web_api_key' => 'env-key-should-be-ignored']);
    app(SettingsService::class)->set(SettingKey::WEB_API_KEY->value, 'settings-key-1234567890');

    expect($this->withHeaders(['X-Api-Key' => 'settings-key-1234567890'])->getJson('/api/products/product_search')->status())
        ->not->toBe(401);
    $this->withHeaders(['X-Api-Key' => 'env-key-should-be-ignored'])
        ->getJson('/api/products/product_search')
        ->assertStatus(401);
});

it('falls back to the env key when the settings key is empty', function () {
    config(['app.web_api_key' => 'env-fallback-key']);
    app(SettingsService::class)->set(SettingKey::WEB_API_KEY->value, '');

    expect(app(SettingsService::class)->getWebApiKey())->toBe('env-fallback-key');
    expect($this->withHeaders(['X-Api-Key' => 'env-fallback-key'])->getJson('/api/products/product_search')->status())
        ->not->toBe(401);
});

it('sends the settings key on outgoing master node requests', function () {
    config(['app.web_api_key' => 'env-key']);
    app(SettingsService::class)->set(SettingKey::WEB_API_KEY->value, 'settings-outgoing-key-123');
    app(SettingsService::class)->set(SettingKey::MASTER_NODE_LINK->value, 'https://master.example.com');

    Http::fake(['https://master.example.com/*' => Http::response(['message' => 'ok'], 200)]);

    app(BranchService::class)->testMasterConnection();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'settings-outgoing-key-123'));
});
