<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Pages\ShiftMoney\ShiftMoneyPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('shiftMoney')
                ->label('مصروفات وإيرادات الورديات')
                ->icon('heroicon-o-banknotes')
                ->visible(fn (): bool => ShiftMoneyPage::canAccess())
                ->url(fn (): string => ShiftMoneyPage::getUrl()),
        ];
    }
}
