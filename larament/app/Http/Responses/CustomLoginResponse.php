<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class CustomLoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $user = auth()->user();

        if($user->role === UserRole::CASHIER) {
            return redirect()->route('orders.index');
        }

        // Default Filament redirect
        return redirect()->intended(filament()->getUrl());
    }
}
