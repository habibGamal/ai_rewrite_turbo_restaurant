<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settingsService = app(SettingsService::class);

        $user = $request->user();
        $authData = ['user' => $user];

        // Add role and permission data if user is authenticated
        if ($user) {
            $authData['user'] = array_merge($user->toArray(), [
                'roles' => $user->getRoleNames()->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
                'isAdmin' => $user->hasRole('admin'),
            ]);
        }

        return [
            ...parent::share($request),
            'auth' => $authData,
            'receiptFooter' => $settingsService->getReceiptFooter(),
            'scaleBarcodePrefix' => $settingsService->getScaleBarcodePrefix(),
        ];
    }
}
