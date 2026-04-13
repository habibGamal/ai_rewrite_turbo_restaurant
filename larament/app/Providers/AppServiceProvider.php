<?php

namespace App\Providers;

use App\Http\Responses\CustomLoginResponse;
use App\Jobs\ExporterCsv;
use App\Jobs\ImportCsv;
use App\Models\Product;
use App\Observers\ProductObserver;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Imports\Jobs\ImportCsv as BaseImportCsv;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Resources\Json\JsonResource;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Register model observers
        Product::observe(ProductObserver::class);

        JsonResource::withoutWrapping();

        FilamentView::registerRenderHook(
            PanelsRenderHook::GLOBAL_SEARCH_AFTER,
            fn (): View => view('filament.global-actions'),
        );

        if (request()->isSecure() || request()->header('X-Forwarded-Proto') === 'https') {
            URL::forceScheme('https');
        }
    }
}
