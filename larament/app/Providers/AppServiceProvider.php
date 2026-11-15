<?php

namespace App\Providers;

use App\Auth\VendorAwareUserProvider;
use App\Costing\CostHistoryRecorder;
use App\Costing\CostingStrategy;
use App\Costing\CostingStrategyResolver;
use App\Enums\OrderBehavior;
use App\Http\Responses\CustomLoginResponse;
use App\Jobs\ExporterCsv;
use App\Jobs\ImportCsv;
use App\Models\Category;
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
use App\Orders\Behaviors\DeliveryBehavior;
use App\Orders\Behaviors\DineInBehavior;
use App\Orders\Behaviors\InstantSaleBehavior;
use App\Orders\Behaviors\WebDeliveryBehavior;
use App\Orders\Behaviors\WebTakeawayBehavior;
use App\Orders\OrderBehaviorRegistry;
use App\Orders\OrderTypeCatalog;
use App\Policies\RolePolicy;
use App\Services\FeatureService;
use App\Sync\SyncOutboxObserver;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Imports\Jobs\ImportCsv as BaseImportCsv;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
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
        $this->app->bind(BaseImportCsv::class, ImportCsv::class);
        $this->app->bind(ExportCsv::class, ExporterCsv::class);
        $this->app->singleton(LoginResponse::class, CustomLoginResponse::class);
        $this->app->singleton(FeatureService::class);
        $this->app->singleton(OrderBehaviorRegistry::class, function ($app): OrderBehaviorRegistry {
            $registry = new OrderBehaviorRegistry($app);

            // Core behaviours. Phase 4 moves dine_in, delivery and the web
            // behaviours into the Restaurant, Delivery and Web orders modules;
            // until then they name their module here so a shop never sees them.
            $registry->register(OrderBehavior::DINE_IN->value, DineInBehavior::class, 'صالة', module: Module::RESTAURANT);
            $registry->register(OrderBehavior::DELIVERY->value, DeliveryBehavior::class, 'دليفري', module: Module::DELIVERY);
            $registry->register(OrderBehavior::COUNTER->value, CounterBehavior::class, 'كاونتر');
            $registry->register(OrderBehavior::COMPANIES->value, CompaniesBehavior::class, 'شركات');
            $registry->register(OrderBehavior::WEB_DELIVERY->value, WebDeliveryBehavior::class, 'اونلاين دليفري', selectable: false, module: Module::WEB_ORDERS);
            $registry->register(OrderBehavior::WEB_TAKEAWAY->value, WebTakeawayBehavior::class, 'اونلاين تيك أواي', selectable: false, module: Module::WEB_ORDERS);
            $registry->register(OrderBehavior::INSTANT->value, InstantSaleBehavior::class, 'بيع مباشر', selectable: false);

            return $registry;
        });
        $this->app->scoped(OrderTypeCatalog::class);
        $this->app->scoped(CostHistoryRecorder::class);
        $this->app->bind(CostingStrategyResolver::class);
        $this->app->bind(CostingStrategy::class, fn ($app) => $app->make(CostingStrategyResolver::class)->resolve());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Auth::provider('vendor-aware', fn ($app, array $config): VendorAwareUserProvider => new VendorAwareUserProvider($app['hash'], $config['model']));

        // Register model observers
        Product::observe(ProductObserver::class);

        // Platform connector sync outbox observer
        Order::observe(SyncOutboxObserver::class);
        OrderItem::observe(SyncOutboxObserver::class);
        Payment::observe(SyncOutboxObserver::class);
        OrderReturn::observe(SyncOutboxObserver::class);
        Shift::observe(SyncOutboxObserver::class);
        Expense::observe(SyncOutboxObserver::class);
        PurchaseInvoice::observe(SyncOutboxObserver::class);
        ReturnPurchaseInvoice::observe(SyncOutboxObserver::class);
        Waste::observe(SyncOutboxObserver::class);
        Stocktaking::observe(SyncOutboxObserver::class);
        Product::observe(SyncOutboxObserver::class);
        Category::observe(SyncOutboxObserver::class);
        Supplier::observe(SyncOutboxObserver::class);
        ExpenceType::observe(SyncOutboxObserver::class);
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
