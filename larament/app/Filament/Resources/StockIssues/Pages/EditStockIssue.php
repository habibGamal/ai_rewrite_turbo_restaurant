<?php

namespace App\Filament\Resources\StockIssues\Pages;

use App\Filament\Actions\ConfirmStockIssueAction;
use App\Filament\Resources\StockIssues\StockIssueResource;
use App\Services\Resources\StockIssueCalculatorService;
use App\Services\StockIssueService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditStockIssue extends EditRecord
{
    protected static string $resource = StockIssueResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['items']) && is_array($data['items'])) {
            $total = StockIssueCalculatorService::calculateTotal($data['items']);
            $data['total_cost'] = $total;
            $data['total'] = $total;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ConfirmStockIssueAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterValidate(): void
    {
        try {
            app(StockIssueService::class)->validateItems($this->data['items'] ?? []);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$key] = $messages;
                if (! str_starts_with($key, 'data.')) {
                    $errors['data.'.$key] = $messages;
                }
            }

            throw ValidationException::withMessages($errors);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
