<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\FeatureService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property Role $record
 */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(function (): bool {
                    /** @var Role $record */
                    $record = $this->getRecord();

                    return $record->name === 'admin';
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();
        $rolePermissions = $record->permissions->pluck('name')->toArray();
        $catalog = RoleResource::visiblePermissionsCatalog();

        $data['permissions_catalog'] = array_values(array_intersect($rolePermissions, array_keys($catalog['إدارة المنتجات والقوائم'] ?? [])));
        $data['permissions_purchasing'] = array_values(array_intersect($rolePermissions, array_keys($catalog['المشتريات والمخزون'] ?? [])));
        $data['permissions_customers'] = array_values(array_intersect($rolePermissions, array_keys($catalog['العملاء والسائقين والمصروفات'] ?? [])));
        $data['permissions_system'] = array_values(array_intersect($rolePermissions, array_keys($catalog['إدارة النظام'] ?? [])));
        $data['permissions_reports'] = array_values(array_intersect($rolePermissions, array_keys($catalog['التقارير'] ?? [])));
        $data['permissions_pos'] = array_values(array_intersect($rolePermissions, array_keys($catalog['نقطة البيع والعمليات (POS)'] ?? [])));
        $data['permissions_kitchen'] = array_values(array_intersect($rolePermissions, array_keys($catalog['شاشة المطبخ'] ?? [])));

        return $data;
    }

    protected function afterSave(): void
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
        )));

        $visiblePermissions = array_values(array_diff(
            $selectedPermissions,
            app(FeatureService::class)->disabledFeaturePermissions(),
        ));

        /** @var Role $record */
        $record = $this->getRecord();
        $finalPermissions = app(FeatureService::class)->preserveHiddenRolePermissions($record, $visiblePermissions);
        $record->syncPermissions($finalPermissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
