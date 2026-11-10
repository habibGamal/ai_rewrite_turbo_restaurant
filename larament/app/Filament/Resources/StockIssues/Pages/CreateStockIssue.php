<?php

namespace App\Filament\Resources\StockIssues\Pages;

use App\Filament\Resources\StockIssues\StockIssueResource;
use App\Services\StockIssueService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateStockIssue extends CreateRecord
{
    protected static string $resource = StockIssueResource::class;

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
