<?php

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shift = Shift::factory()->active()->create(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
});

it('marks hot drinks as blocked for delivery in the POS payload', function () {
    $drinks = Category::factory()->create(['name' => 'مشروبات']);
    $hot = Category::factory()->create([
        'name' => 'مشروبات ساخنة',
        'parent_id' => $drinks->id,
    ]);
    $direct = Product::factory()->create([
        'category_id' => $drinks->id,
        'name' => 'مياه',
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $tea = Product::factory()->create([
        'category_id' => $hot->id,
        'name' => 'شاي',
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $delivery = OrderTypeRow::query()->where('code', 'delivery')->firstOrFail();
    $hot->blockedOrderTypes()->attach($delivery->id);

    $order = Order::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->user->id,
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
    ]);

    $this->get(route('orders.manage', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/ManageOrder')
            ->where('categories', function ($categories) use ($drinks, $hot, $direct, $tea) {
                $main = collect($categories)->firstWhere('id', $drinks->id);

                expect($main)->not->toBeNull()
                    ->and($main['parent_id'])->toBeNull()
                    ->and(collect($main['products'])->pluck('id'))->toContain($direct->id)
                    ->and(collect($main['products'])->pluck('id'))->not->toContain($tea->id);

                $child = collect($main['children'])->firstWhere('id', $hot->id);

                expect($child)->not->toBeNull()
                    ->and($child['blocked_order_type_codes'])->toContain('delivery')
                    ->and(collect($child['products'])->pluck('id'))->toContain($tea->id);

                return true;
            }));
});

it('rejects adding a blocked item with an Arabic message', function () {
    $desserts = Category::factory()->create(['name' => 'حلويات']);
    $cakes = Category::factory()->create([
        'name' => 'كيك',
        'parent_id' => $desserts->id,
    ]);
    $takeaway = OrderTypeRow::query()->where('code', 'takeaway')->firstOrFail();
    $desserts->blockedOrderTypes()->attach($takeaway->id);
    $product = Product::factory()->create([
        'category_id' => $cakes->id,
        'price' => 25,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $order = Order::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->user->id,
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
    ]);

    $this->post(route('orders.save', $order), [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 25,
        ]],
    ])->assertRedirect()
        ->assertSessionHasErrors('error');

    expect(session('errors')->first('error'))->toContain('محظور');
    $this->assertDatabaseMissing('order_items', [
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);
});

it('allows changing an open order to delivery and keeps the blocked items', function () {
    $desserts = Category::factory()->create(['name' => 'حلويات']);
    $delivery = OrderTypeRow::query()->where('code', 'delivery')->firstOrFail();
    $desserts->blockedOrderTypes()->attach($delivery->id);
    $product = Product::factory()->create([
        'category_id' => $desserts->id,
        'price' => 40,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    $order = Order::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->user->id,
        'status' => OrderStatus::PROCESSING,
        'type' => 'takeaway',
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 40,
        'cost' => 10,
        'total' => 40,
    ]);

    $this->post(route('orders.updateType', $order), [
        'type' => 'delivery',
    ])->assertRedirect()
        ->assertSessionHas('success', 'تم تغيير نوع الطلب بنجاح');

    expect($order->fresh()->type)->toBe('delivery');
    $this->assertDatabaseHas('order_items', [
        'order_id' => $order->id,
        'product_id' => $product->id,
    ]);

    $this->post(route('orders.save', $order), [
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 40,
        ]],
    ])->assertRedirect()
        ->assertSessionHas('success');
});

it('hides blocked categories on the web storefront for its order type', function () {
    $visible = Category::factory()->create(['name' => 'مشاوي']);
    $blocked = Category::factory()->create(['name' => 'حلويات']);
    $child = Category::factory()->create([
        'name' => 'كيك',
        'parent_id' => $blocked->id,
    ]);
    $webDelivery = OrderTypeRow::query()->where('code', 'web_delivery')->firstOrFail();
    $blocked->blockedOrderTypes()->attach($webDelivery->id);

    Product::factory()->create([
        'category_id' => $visible->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    Product::factory()->create([
        'category_id' => $blocked->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);
    Product::factory()->create([
        'category_id' => $child->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    $order = Order::factory()->create([
        'type' => 'web_delivery',
        'status' => OrderStatus::PENDING,
    ]);

    $this->get(route('web-orders.manage', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Orders/ManageWebOrder')
            ->where('categories', function ($categories) use ($visible, $blocked, $child) {
                $ids = collect($categories)->pluck('id');

                expect($ids)->toContain($visible->id)
                    ->and($ids)->not->toContain($blocked->id)
                    ->and($ids)->not->toContain($child->id);

                return true;
            }));
});

it('rejects a web storefront order that adds a blocked item', function () {
    config(['app.web_api_key' => 'secret-test-key']);

    $category = Category::factory()->create(['name' => 'حلويات']);
    $webDelivery = OrderTypeRow::query()->where('code', 'web_delivery')->firstOrFail();
    $category->blockedOrderTypes()->attach($webDelivery->id);
    Product::factory()->create([
        'category_id' => $category->id,
        'product_ref' => 'CAKE1',
        'price' => 20,
        'cost' => 5,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    $response = $this->withHeaders(['X-Api-Key' => 'secret-test-key'])
        ->postJson('/api/web-orders/place-order', [
            'user' => [
                'name' => 'عميل',
                'phone' => '01000000000',
                'area' => 'مدينة نصر',
                'address' => 'شارع 1',
            ],
            'order' => [
                'type' => 'web_delivery',
                'shiftId' => $this->shift->id,
                'orderNumber' => 'W-1',
                'subTotal' => 20,
                'tax' => 0,
                'service' => 0,
                'discount' => 0,
                'total' => 20,
                'items' => [[
                    'quantity' => 1,
                    'posRefObj' => [[
                        'productRef' => 'CAKE1',
                        'quantity' => 1,
                    ]],
                ]],
            ],
        ]);

    $response->assertStatus(400);
    expect($response->json('error'))->toContain('محظور');
    expect(Order::query()->count())->toBe(0);
});

it('adds parent_id to api category payloads', function () {
    config(['app.web_api_key' => 'secret-test-key']);

    $main = Category::factory()->create(['name' => 'مشروبات']);
    $sub = Category::factory()->create([
        'name' => 'مشروبات ساخنة',
        'parent_id' => $main->id,
    ]);
    Product::factory()->create([
        'category_id' => $sub->id,
        'type' => ProductType::Manufactured,
        'legacy' => false,
    ]);

    $headers = ['X-Api-Key' => 'secret-test-key'];

    $prices = $this->withHeaders($headers)->getJson('/api/all-products-prices-master');
    $prices->assertOk();
    $priceRows = collect($prices->json());

    expect($priceRows->firstWhere('id', $main->id))->toHaveKeys(['id', 'name', 'parentId'])
        ->and($priceRows->firstWhere('id', $main->id)['parentId'])->toBeNull()
        ->and($priceRows->firstWhere('id', $sub->id)['parentId'])->toBe($main->id);

    $recipes = $this->withHeaders($headers)->getJson('/api/all-products-recipes-master');
    $recipes->assertOk();
    $recipeRows = collect($recipes->json());

    expect($recipeRows->firstWhere('id', $sub->id))->toHaveKeys(['id', 'name', 'products', 'parentId'])
        ->and($recipeRows->firstWhere('id', $sub->id)['parentId'])->toBe($main->id);
});
