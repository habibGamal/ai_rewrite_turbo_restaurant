<?php

namespace App\Providers;

use App\Auth\BadgeService;
use App\Auth\VendorAwareUserProvider;
use App\Broadcasting\ResilientBroadcastManager;
use App\Costing\CostHistoryRecorder;
use App\Costing\CostingStrategy;
use App\Costing\CostingStrategyResolver;
use App\Enums\OrderBehavior;
use App\Http\Responses\CustomLoginResponse;
use App\Jobs\ExporterCsv;
use App\Jobs\ImportCsv;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\ExternalSeller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductComponent;
use App\Models\ProductGroup;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Waste;
use App\Modules\Module;
use App\Observers\ProductObserver;
use App\Orders\Behaviors\CompaniesBehavior;
use App\Orders\Behaviors\CounterBehavior;
use App\Orders\Behaviors\InstantSaleBehavior;
use App\Orders\OrderBehaviorRegistry;
use App\Orders\OrderTypeCatalog;
use App\Policies\RolePolicy;
use App\Services\FeatureService;
use App\Sync\SyncOutboxObserver;
use App\Terminals\TerminalSessionResolver;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Imports\Jobs\ImportCsv as BaseImportCsv;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Login;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A down Reverb server must never fail an order, payment or web order:
        // network broadcasts are logged and dropped instead of throwing.
        $this->app->extend(BroadcastManager::class, fn (BroadcastManager $manager, $app): BroadcastManager => $manager instanceof ResilientBroadcastManager ? $manager : new ResilientBroadcastManager($app));
        $this->app->bind(BaseImportCsv::class, ImportCsv::class);
        $this->app->bind(ExportCsv::class, ExporterCsv::class);
        $this->app->singleton(LoginResponse::class, CustomLoginResponse::class);
        $this->app->singleton(FeatureService::class);
        $this->app->singleton(OrderBehaviorRegistry::class, function ($app): OrderBehaviorRegistry {
            $registry = new OrderBehaviorRegistry($app);

            // Core behaviours. Module behaviours (dine_in, delivery, web_*)
            // are added by their module's service provider.
            $registry->register(OrderBehavior::COUNTER->value, CounterBehavior::class, 'كاونتر', sort: 30);
            $registry->register(OrderBehavior::COMPANIES->value, CompaniesBehavior::class, 'شركات', sort: 40);
            $registry->register(OrderBehavior::INSTANT->value, InstantSaleBehavior::class, 'بيع مباشر', selectable: false, sort: 70);

            return $registry;
        });
        $this->app->scoped(OrderTypeCatalog::class);
        $this->app->scoped(CostHistoryRecorder::class);
        $this->app->scoped(TerminalSessionResolver::class);
        $this->app->bind(CostingStrategyResolver::class);
        $this->app->bind(CostingStrategy::class, fn ($app) => $app->make(CostingStrategyResolver::class)->resolve());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // A password login clears the POS-only mark a badge login leaves.
        Event::listen(Login::class, function (): void {
            if (app()->bound('session')) {
                session()->forget(BadgeService::SESSION_KEY);
            }
        });

        Auth::provider('vendor-aware', fn ($app, array $config): VendorAwareUserProvider => new VendorAwareUserProvider($app['hash'], $config['model']));

        // Register model observers
        Product::observe(ProductObserver::class);

        // Platform connector sync outbox observer
        Order::observe(SyncOutboxObserver::class);
        AuditLog::observe(SyncOutboxObserver::class);
        OrderItem::observe(SyncOutboxObserver::class);
        Payment::observe(SyncOutboxObserver::class);
        OrderReturn::observe(SyncOutboxObserver::class);
        Shift::observe(SyncOutboxObserver::class);
        Expense::observe(SyncOutboxObserver::class);
        Revenue::observe(SyncOutboxObserver::class);
        PurchaseInvoice::observe(SyncOutboxObserver::class);
        ReturnPurchaseInvoice::observe(SyncOutboxObserver::class);
        Waste::observe(SyncOutboxObserver::class);
        Stocktaking::observe(SyncOutboxObserver::class);
        Product::observe(SyncOutboxObserver::class);
        Category::observe(SyncOutboxObserver::class);
        Supplier::observe(SyncOutboxObserver::class);
        ExpenceType::observe(SyncOutboxObserver::class);
        RevenueType::observe(SyncOutboxObserver::class);
        User::observe(SyncOutboxObserver::class);
        ProductComponent::observe(SyncOutboxObserver::class);
        ProductGroup::observe(SyncOutboxObserver::class);
        ProductOption::observe(SyncOutboxObserver::class);
        ProductOptionValue::observe(SyncOutboxObserver::class);
        ProductBarcode::observe(SyncOutboxObserver::class);

        JsonResource::withoutWrapping();

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_AFTER,
            fn (): View => view('filament.global-actions'),
        );

        Event::listen(TransactionRolledBack::class, static function (): void {
            Setting::invalidateCache();
        });

        // Enforce optional feature gates before granting admin role authorization bypass
        Gate::before(function ($user, $ability, array $arguments = []) {
            // vendor.* abilities belong to the vendor account alone; the
            // customer's admin role does not bypass them.
            if (is_string($ability) && str_starts_with($ability, 'vendor.')) {
                return $user instanceof User && $user->isVendor();
            }

            $featureService = app(FeatureService::class);

            if (is_string($ability) && $featureService->isPermissionDisabled($ability)) {
                return false;
            }

            foreach ($arguments as $argument) {
                $class = is_object($argument) ? $argument::class : (is_string($argument) ? $argument : null);
                if ($class === ExternalSeller::class && ! $featureService->outsourcedOrdersEnabled()) {
                    return false;
                }
                if ($class === Equipment::class && ! $featureService->equipmentEnabled()) {
                    return false;
                }
            }

            if ($user instanceof User && $user->isVendor()) {
                return true;
            }

            return $user->hasRole('admin') ? true : null;
        });

        Gate::policy(Role::class, RolePolicy::class);

        if (request()->isSecure() || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }
}
