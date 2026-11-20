<?php

namespace App\Filament\Resources\StockIssues\Pages;

use App\Filament\Actions\ConfirmStockIssueAction;
use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Resources\StockIssues\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\StockIssues\StockIssueResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStockIssue extends ViewRecord
{
    protected static string $resource = StockIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PrintLabelsAction::document(),
            ConfirmStockIssueAction::make(),
            EditAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
