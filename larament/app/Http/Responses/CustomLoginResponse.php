<?php

namespace App\Http\Responses;

use App\Filament\Pages\SetupWizard;
use App\Services\Setup\BusinessProfileService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class CustomLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = auth()->user();

        // A fresh install sends the vendor to the first-run setup.
        if ($user && SetupWizard::canAccess() && ! app(BusinessProfileService::class)->completed()) {
            return redirect()->to(SetupWizard::getUrl());
        }

        if ($user && $user->hasRole('cashier')) {
            return redirect()->route('orders.index');
        }

        // Default Filament redirect
        return redirect()->intended(filament()->getUrl());
    }
}
