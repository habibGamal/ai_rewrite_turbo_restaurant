<?php

use App\Enums\SettingKey;
use App\Models\User;
use App\Services\SettingsService;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('serves the customer display with the shop name', function () {
    app(SettingsService::class)->set(SettingKey::RESTAURANT_NAME->value, 'بوتيك النور');
    actingAs(User::factory()->admin()->create());

    get(route('pos.customer-display'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pos/CustomerDisplay')
            ->where('shopName', 'بوتيك النور'));
});

it('needs a signed-in user', function () {
    auth()->logout();

    get(route('pos.customer-display'))->assertRedirect();
});
