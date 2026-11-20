<?php

use App\Enums\OptionalFeature;
use App\Exceptions\OrderException;
use App\Filament\Pages\CompaniesAccounts;
use App\Filament\Pages\Reports\ReturnsReport;
use App\Filament\Resources\OrderReturns\OrderReturnResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Orders\CashierTabLayout;
use App\Orders\OrderTypeCatalog;
use App\Services\FeatureService;
use App\Services\Orders\OrderReturnService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Str;
use Tests\Support\SafetyNet;

beforeEach(function () {
    ['user' => $this->user, 'shift' => $this->shift] = SafetyNet::till();
    $this->features = app(FeatureService::class);
    $this->orders = app(OrderService::class);
});

it('starts with both features off on a fresh install', function () {
    expect($this->features->deferredPaymentEnabled())->toBeFalse()
        ->and($this->features->returnsEnabled())->toBeFalse()
        ->and(OptionalFeature::standalone())->toContain(OptionalFeature::DEFERRED_PAYMENT, OptionalFeature::RETURNS);
});

describe('deferred payment off', function () {
    it('treats every order type as pay in full and refuses closing unpaid', function () {
        $companies = OrderTypeRow::query()->where('code', 'companies')->firstOrFail();
        expect((bool) $companies->allow_pay_later)->toBeTrue()
            ->and($companies->allowsCredit())->toBeFalse()
            ->and($companies->creditFlags())->toBe([
                'allow_partial_payment' => false,
                'allow_pay_later' => false,
                'require_customer_for_credit' => false,
            ])
            ->and(app(OrderTypeCatalog::class)->creditCodes())->toBe([]);

        $order = SafetyNet::open($this->shift, $this->user, 'companies');
        SafetyNet::save($order, [[SafetyNet::product(price: 100), 1]]);
        $this->orders->linkCustomer($order->id, Customer::factory()->create()->id);

        expect(fn () => $this->orders->completeOrder($order->id, []))
            ->toThrow(OrderException::class);
    });

    it('drops the receivables tab, its permission and the companies accounts page', function () {
        $keys = array_column(app(CashierTabLayout::class)->resolve(null), 'key');

        expect($keys)->not->toContain('system:receivables')
            ->and($this->user->can('pos.tab.system.receivables'))->toBeFalse()
            ->and($this->features->isPermissionDisabled('pos.tab.system.receivables'))->toBeTrue();

        $this->get(CompaniesAccounts::getUrl())->assertNotFound();
        $this->post(route('orders.collectRemaining', Order::factory()->create()))->assertNotFound();
    });

    it('brings everything back once the vendor turns it on', function () {
        $this->features->enable(OptionalFeature::DEFERRED_PAYMENT);

        expect(array_column(app(CashierTabLayout::class)->resolve(null), 'key'))->toContain('system:receivables')
            ->and(OrderTypeRow::query()->where('code', 'companies')->firstOrFail()->allowsCredit())->toBeTrue();

        $this->get(CompaniesAccounts::getUrl())->assertOk();
    });

    it('keeps the stored receivables tab place while the feature is off', function () {
        $layout = app(CashierTabLayout::class);
        $this->features->enable(OptionalFeature::DEFERRED_PAYMENT);
        $before = array_column($layout->resolve(null), 'key');

        $this->features->disable(OptionalFeature::DEFERRED_PAYMENT);
        $layout->save(array_map(fn (array $tab): array => ['key' => $tab['key'], 'visible' => $tab['visible']], $layout->entriesForEditor()));

        $this->features->enable(OptionalFeature::DEFERRED_PAYMENT);
        expect(array_column($layout->resolve(null), 'key'))->toBe($before);
    });
});

describe('returns off', function () {
    it('denies every return permission, even to the admin', function () {
        foreach (OptionalFeature::RETURNS->permissions() as $permission) {
            expect($this->user->can($permission))->toBeFalse();
        }
    });

    it('hides the return screens and the cashier return endpoints', function () {
        $this->get(OrderReturnResource::getUrl())->assertNotFound();
        $this->get(ReturnsReport::getUrl())->assertNotFound();
        $this->getJson(route('pos.instant-returns.lookup', ['q' => '#1']))->assertNotFound();
        $this->postJson(route('pos.instant-returns.store'), ['client_uuid' => (string) Str::uuid()])->assertNotFound();
    });

    it('refuses a return in the service layer', function () {
        $order = SafetyNet::open($this->shift, $this->user);
        SafetyNet::save($order, [[SafetyNet::product(price: 10), 1]]);
        $this->orders->completeOrder($order->id, ['cash' => 10]);

        expect(fn () => app(OrderReturnService::class)->processReturn(
            $order->fresh(),
            [['order_item_id' => $order->items()->first()->id, 'quantity' => 1]],
            'test',
            [['method' => 'cash', 'amount' => 10]],
            $this->shift->id,
        ))->toThrow(OrderException::class, 'ميزة المرتجعات غير مفعلة حالياً.');
    });

    it('opens the returns screens once the vendor turns it on', function () {
        $this->features->enable(OptionalFeature::RETURNS);

        $this->get(ReturnsReport::getUrl())->assertOk();
        expect($this->user->can('order.return'))->toBeTrue();
    });
});
