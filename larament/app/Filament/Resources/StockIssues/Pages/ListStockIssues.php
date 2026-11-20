<?php

namespace App\Filament\Resources\StockIssues\Pages;

use App\Filament\Resources\StockIssues\StockIssueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockIssues extends ListRecords
{
    protected static string $resource = StockIssueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
