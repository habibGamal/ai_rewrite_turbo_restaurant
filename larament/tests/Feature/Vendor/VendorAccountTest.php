<?php

use App\Filament\Pages\VendorConsole;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\FeatureFlagAudit;
use App\Models\Order;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Services\CashierCommissionReportService;
use App\Services\FeatureService;
use App\Sync\BackfillService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    foreach (['access_admin_panel', 'manage_settings', 'view_users', 'update_users', 'view_stocktakings'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'])->givePermissionTo(Permission::all());
});

it('creates the vendor with pos:vendor and resets its password on a second run', function () {
    artisan('pos:vendor', ['--password' => 'first-secret-1'])->assertSuccessful();

    $vendor = User::query()->onlyVendor()->sole();
    expect($vendor->name)->toBe('الدعم الفني')
        ->and($vendor->email)->toBe('support@pos.local')
        ->and($vendor->roles)->toBeEmpty()
        ->and(Hash::check('first-secret-1', $vendor->password))->toBeTrue();

    artisan('pos:vendor', ['--password' => 'second-secret-2'])->assertSuccessful();

    expect(User::query()->onlyVendor()->count())->toBe(1)
        ->and(Hash::check('second-secret-2', $vendor->fresh()->password))->toBeTrue();
});

it('refuses an email that belongs to a customer user', function () {
    User::factory()->create(['email' => 'owner@shop.test']);

    artisan('pos:vendor', ['--email' => 'owner@shop.test'])->assertFailed();
    expect(User::query()->onlyVendor()->exists())->toBeFalse();
});

it('lets the vendor log in although it is hidden from user queries', function () {
    artisan('pos:vendor', ['--password' => 'vendor-secret-1'])->assertSuccessful();

    expect(Auth::attempt(['email' => 'support@pos.local', 'password' => 'vendor-secret-1']))->toBeTrue()
        ->and(Auth::user()->isVendor())->toBeTrue();
});

it('hides the vendor from user lists, pickers and counts', function () {
    $admin = User::factory()->admin()->create();
    $vendor = User::factory()->vendor()->create();

    expect(User::all()->pluck('id')->all())->toContain($admin->id)->not->toContain($vendor->id)
        ->and(User::query()->pluck('name', 'id')->has($vendor->id))->toBeFalse()
        ->and(User::query()->whereIn('id', [$admin->id, $vendor->id])->count())->toBe(1)
        ->and(User::query()->withVendor()->count())->toBe(User::query()->count() + 1)
        ->and(Role::findByName('admin')->users()->pluck('users.id')->all())->not->toContain($vendor->id);
});

it('never shows the vendor in the Users resource, even by direct URL', function () {
    $admin = User::factory()->admin()->create();
    $vendor = User::factory()->vendor()->create();
    actingAs($admin);

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$admin])
        ->assertCanNotSeeTableRecords([$vendor]);

    get(UserResource::getUrl('edit', ['record' => $vendor]))->assertNotFound();
});

it('keeps records created by the vendor readable as الدعم الفني', function () {
    $vendor = User::factory()->vendor()->create();
    $order = Order::factory()->create(['user_id' => $vendor->id]);

    expect($order->fresh()->user?->name)->toBe('الدعم الفني')
        ->and(Order::query()->with('user')->find($order->id)->user?->name)->toBe('الدعم الفني');
});

it('leaves the vendor out of the cashier performance report', function () {
    $admin = User::factory()->admin()->create();
    $vendor = User::factory()->vendor()->create();
    Order::factory()->create(['user_id' => $vendor->id]);
    Order::factory()->create(['user_id' => $admin->id]);

    $ids = app(CashierCommissionReportService::class)->getCashierCommissionQuery()->pluck('users.id')->all();

    expect($ids)->not->toContain($vendor->id);
});

it('never sends the vendor in the staff sync', function () {
    pairPlatform();
    SyncOutbox::truncate();

    $vendor = User::factory()->vendor()->create();
    $staff = User::factory()->create();

    $outboxUuids = SyncOutbox::query()->where('doc_type', 'staff')->pluck('doc_uuid')->all();
    expect($outboxUuids)->toContain($staff->uuid)->not->toContain($vendor->uuid);

    SyncOutbox::truncate();
    app(BackfillService::class)->queueCatalog();

    expect(SyncOutbox::query()->where('doc_type', 'staff')->pluck('doc_uuid')->all())
        ->toContain($staff->uuid)
        ->not->toContain($vendor->uuid);
});

it('grants the vendor every permission while vendor abilities stay closed to the admin', function () {
    $admin = User::factory()->admin()->create();
    $vendor = User::factory()->vendor()->create();

    expect($vendor->can('manage_settings'))->toBeTrue()
        ->and($vendor->can('anything_at_all'))->toBeTrue()
        ->and($vendor->isAdmin())->toBeTrue()
        ->and($vendor->can('vendor.console'))->toBeTrue()
        ->and($admin->can('vendor.console'))->toBeFalse();
});

it('gives the customer admin a 403 on the vendor console and hides it from the menu', function () {
    actingAs(User::factory()->admin()->create());

    get(VendorConsole::getUrl())->assertForbidden();
    expect(VendorConsole::shouldRegisterNavigation())->toBeFalse();
});

it('opens the vendor console for the vendor', function () {
    actingAs(User::factory()->vendor()->create());

    get(VendorConsole::getUrl())->assertSuccessful()->assertSee('لوحة الدعم الفني');
});

it('sets the business type and modules from the vendor console before the first order', function () {
    $vendor = User::factory()->vendor()->create();
    actingAs($vendor);

    livewire(VendorConsole::class)
        ->fillForm(['business_type' => BusinessType::GROCERY->value])
        ->assertFormSet(['modules' => ['retail', 'delivery']])
        ->set('data.modules', ['retail', 'delivery', 'web_orders'])
        ->call('save')
        ->assertHasNoErrors();

    $features = app(FeatureService::class);
    expect($features->businessType())->toBe(BusinessType::GROCERY)
        ->and($features->enabledModules())->toBe([Module::DELIVERY, Module::WEB_ORDERS, Module::RETAIL]);

    $audit = FeatureFlagAudit::query()->where('feature_key', 'business_type')->sole();
    expect($audit->new_text)->toBe('grocery')->and($audit->user_id)->toBe($vendor->id);
    expect(FeatureFlagAudit::query()->where('feature_key', 'module.retail')->sole()->user_id)->toBe($vendor->id);
});

it('does not change the business type from the vendor console once an order exists', function () {
    Order::factory()->create();
    actingAs(User::factory()->vendor()->create());

    livewire(VendorConsole::class)
        ->assertFormFieldIsDisabled('business_type')
        ->fillForm(['business_type' => BusinessType::FASHION->value, 'modules' => ['restaurant', 'retail']])
        ->call('save');

    $features = app(FeatureService::class);
    expect($features->businessType())->toBe(BusinessType::RESTAURANT)
        ->and($features->enabledModules())->toBe([Module::RESTAURANT, Module::RETAIL]);
});
