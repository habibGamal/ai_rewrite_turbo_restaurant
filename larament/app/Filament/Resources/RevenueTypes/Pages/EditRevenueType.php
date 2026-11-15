<?php

namespace App\Filament\Resources\RevenueTypes\Pages;

use App\Filament\Resources\RevenueTypes\RevenueTypeResource;
use App\Models\RevenueType;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRevenueType extends EditRecord
{
    protected static string $resource = RevenueTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (RevenueType $record): bool => $record->revenues()->exists()),
        ];
    }
}
