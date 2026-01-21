<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ShiftService;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Get current shift and add it to the data
        $shiftService = app(ShiftService::class);
        $currentShift = $shiftService->getCurrentShift();

        if ($currentShift) {
            $data['shift_id'] = $currentShift->id;
        }

        return $data;
    }
}
