<?php

namespace App\Filament\Actions;

use App\Filament\Resources\StockIssues\StockIssueResource;
use App\Models\StockIssue;
use App\Services\StockIssueService;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ConfirmStockIssueAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'confirm')
            ->label('تأكيد الصرف')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (StockIssue $record): bool => StockIssueResource::canEdit($record))
            ->requiresConfirmation()
            ->modalHeading('تأكيد إذن الصرف')
            ->modalDescription('هل أنت متأكد من تأكيد إذن الصرف؟ سيتم خصم جميع الأصناف من المخزون بالتكلفة الحالية ولن يمكن تعديل الإذن بعد ذلك.')
            ->modalSubmitActionLabel('تأكيد الصرف')
            ->modalCancelActionLabel('إلغاء')
            ->action(function (StockIssue $record, $livewire) {
                abort_unless(StockIssueResource::canEdit($record), 403);

                try {
                    if (is_object($livewire) && method_exists($livewire, 'save')) {
                        $livewire->save(true);
                    }
                    app(StockIssueService::class)->confirm($record);

                    Notification::make()
                        ->title('تم تأكيد إذن الصرف بنجاح')
                        ->body('تم خصم جميع الأصناف من المخزون بالتكلفة الحالية')
                        ->success()
                        ->send();
                } catch (Exception $e) {
                    Notification::make()
                        ->title('فشل في تأكيد إذن الصرف')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function table(?string $name = null): Action
    {
        return Action::make($name ?? 'confirm')
            ->label('تأكيد الصرف')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (StockIssue $record): bool => StockIssueResource::canEdit($record))
            ->requiresConfirmation()
            ->modalHeading('تأكيد إذن الصرف')
            ->modalDescription('هل أنت متأكد من تأكيد إذن الصرف؟ سيتم خصم جميع الأصناف من المخزون بالتكلفة الحالية ولن يمكن تعديل الإذن بعد ذلك.')
            ->modalSubmitActionLabel('تأكيد الصرف')
            ->modalCancelActionLabel('إلغاء')
            ->action(function (StockIssue $record) {
                abort_unless(StockIssueResource::canEdit($record), 403);

                try {
                    app(StockIssueService::class)->confirm($record);

                    Notification::make()
                        ->title('تم تأكيد إذن الصرف بنجاح')
                        ->body('تم خصم جميع الأصناف من المخزون بالتكلفة الحالية')
                        ->success()
                        ->send();
                } catch (Exception $e) {
                    Notification::make()
                        ->title('فشل في تأكيد إذن الصرف')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
