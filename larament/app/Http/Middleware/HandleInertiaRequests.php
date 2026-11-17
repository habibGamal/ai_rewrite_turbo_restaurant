<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Terminals\TerminalSessionResolver;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

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
        $featureService = app(FeatureService::class);
        $features = $featureService->toInertiaProps();
        $moduleProps = $featureService->moduleInertiaProps();

        $user = $request->user();
        $authData = ['user' => $user];

        // Add role and permission data if user is authenticated
        if ($user) {
            // The vendor holds no role; it is granted every permission.
            $grantedPermissions = $user->isVendor()
                ? Permission::query()->pluck('name')->all()
                : $user->getAllPermissions()->pluck('name')->toArray();

            $visiblePermissions = array_values(array_filter(
                $grantedPermissions,
                fn (string $permission): bool => ! $featureService->isPermissionDisabled($permission),
            ));

            $authData['user'] = array_merge($user->toArray(), [
                'roles' => $user->getRoleNames()->toArray(),
                'permissions' => $visiblePermissions,
                'isAdmin' => $user->isAdmin(),
            ]);
        }

        return [
            ...parent::share($request),
            'auth' => $authData,
            'features' => $features,
            'modules' => $moduleProps['modules'],
            'businessType' => $moduleProps['business_type'],
            'receiptFooter' => $settingsService->getReceiptFooter(),
            'scaleBarcodePrefix' => $settingsService->getScaleBarcodePrefix(),
            'tabsOrientation' => $settingsService->getCashierTabsOrientation(),
            'terminal' => $this->terminalProps($featureService),
        ];
    }

    /**
     * The drawer of this device, for the cashier screen's bottom bar. Null
     * while multiple terminals are off, so nothing about drawers shows.
     *
     * printAgent: receipts of this drawer go through the Turbo Label app on
     * this PC, so the cashier screen keeps the app running.
     *
     * @return array{id: int, name: string, sessionId: int|null, cashier: string|null, openedAt: string|null, printAgent: bool}|null
     */
    private function terminalProps(FeatureService $features): ?array
    {
        if (! $features->multiTerminalEnabled()) {
            return null;
        }

        $resolver = app(TerminalSessionResolver::class);
        $terminal = $resolver->terminal();

        if ($terminal === null) {
            return null;
        }

        $session = $resolver->current();

        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'sessionId' => $session?->id,
            'cashier' => $session?->user?->name,
            'openedAt' => $session?->opened_at->toIso8601String(),
            'printAgent' => $terminal->printsViaAgent(),
        ];
    }
}
