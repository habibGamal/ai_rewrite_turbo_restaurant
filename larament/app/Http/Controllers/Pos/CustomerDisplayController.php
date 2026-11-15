<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer-facing monitor. The cashier screen publishes the cart to it
 * through the browser (same PC, another window), so this only serves the page.
 */
class CustomerDisplayController extends Controller
{
    public function __invoke(SettingsService $settings): Response
    {
        return Inertia::render('Pos/CustomerDisplay', [
            'shopName' => $settings->getRestaurantName(),
        ]);
    }
}
