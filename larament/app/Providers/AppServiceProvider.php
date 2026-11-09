<?php

namespace App\Providers;

use App\Http\Responses\CustomLoginResponse;
use App\Jobs\ExporterCsv;
use App\Jobs\ImportCsv;
use App\Models\Category;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\PurchaseInvoice;
use App\Models\ReturnPurchaseInvoice;
use App\Models\Shift;
use App\Models\Stocktaking;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Waste;
use App\Observers\ProductObserver;
use App\Orders\OrderTypeCatalog;
use App\Sync\SyncOutboxObserver;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Imports\Jobs\ImportCsv as BaseImportCsv;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        $this->app->scoped(OrderTypeCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

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

        JsonResource::withoutWrapping();

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_AFTER,
            fn (): View => view('filament.global-actions'),
        );

        // Grant admin role full authorization bypass
        Gate::before(function ($user, $ability) {
            return $user->hasRole('admin') ? true : null;
        });

        Gate::policy(\Spatie\Permission\Models\Role::class, \App\Policies\RolePolicy::class);

        if (request()->isSecure() || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }
}
