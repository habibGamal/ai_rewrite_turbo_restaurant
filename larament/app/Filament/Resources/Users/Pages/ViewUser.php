<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\RelationManagers\CashierItemChangesRelationManager;
use App\Filament\Resources\Users\RelationManagers\CashierOrdersRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\CashierDetailStatsWidget;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل أداء الكاشير: '.($this->record->name ?? '');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CashierDetailStatsWidget::class,
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            CashierOrdersRelationManager::class,
            CashierItemChangesRelationManager::class,
        ];
    }
}
