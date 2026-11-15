<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\FeatureService;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->data;

        $selectedPermissions = array_values(array_unique(array_merge(
            is_array($data['permissions_catalog'] ?? null) ? $data['permissions_catalog'] : [],
            is_array($data['permissions_purchasing'] ?? null) ? $data['permissions_purchasing'] : [],
            is_array($data['permissions_customers'] ?? null) ? $data['permissions_customers'] : [],
            is_array($data['permissions_system'] ?? null) ? $data['permissions_system'] : [],
            is_array($data['permissions_reports'] ?? null) ? $data['permissions_reports'] : [],
            is_array($data['permissions_pos'] ?? null) ? $data['permissions_pos'] : [],
            is_array($data['permissions_kitchen'] ?? null) ? $data['permissions_kitchen'] : [],
            is_array($data['permissions_pos_tabs'] ?? null) ? $data['permissions_pos_tabs'] : [],
        )));

        $visiblePermissions = array_values(array_diff(
            $selectedPermissions,
            app(FeatureService::class)->disabledFeaturePermissions(),
        ));

        /** @var Role $record */
        $record = $this->record;
        $record->syncPermissions($visiblePermissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
