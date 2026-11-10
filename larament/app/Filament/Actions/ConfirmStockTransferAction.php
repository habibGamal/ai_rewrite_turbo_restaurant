<?php

namespace App\Filament\Actions;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\StockTransferService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ConfirmStockTransferAction
{
    public static function make(?string $name = null): Action
    {
        return static::table($name)->action(function (StockTransfer $record, $livewire): void {
            abort_unless(StockTransferResource::canEdit($record), 403);
            if (method_exists($livewire, 'save')) {
                $livewire->save(shouldRedirect: false);
            }
            app(StockTransferService::class)->confirm($record);
            Notification::make()->title('تم تأكيد التحويل')->success()->send();
            $livewire->redirect(StockTransferResource::getUrl('view', ['record' => $record]));
        });
    }

    public static function table(?string $name = null): Action
    {
        return Action::make($name ?? 'confirm')->label('تأكيد التحويل')
            ->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
            ->visible(fn (StockTransfer $record): bool => StockTransferResource::canEdit($record))
            ->action(function (StockTransfer $record): void {
                abort_unless(StockTransferResource::canEdit($record), 403);
                app(StockTransferService::class)->confirm($record);
                Notification::make()->title('تم تأكيد التحويل')->success()->send();
            });
    }
}
