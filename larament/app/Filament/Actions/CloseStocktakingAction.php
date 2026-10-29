<?php

namespace App\Filament\Actions;

use App\Models\Stocktaking;
use App\Services\ShiftService;
use App\Services\StocktakingService;
use Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

class CloseStocktakingAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'close')
            ->label('إغلاق الجرد')
            ->icon('heroicon-o-lock-closed')
            ->color('success')
            ->visible(fn (Stocktaking $record): bool => is_null($record->closed_at))
            ->requiresConfirmation()
            ->modalHeading('إغلاق الجرد')
            ->modalDescription(fn (Stocktaking $record): string|HtmlString => static::getModalDescription($record))
            ->modalSubmitActionLabel('إغلاق الجرد')
            ->modalCancelActionLabel('إلغاء')
            ->action(function (Stocktaking $record, $livewire) {
                try {
                    $livewire->save(true);
                    $stocktakingService = app(StocktakingService::class);
                    $stocktakingService->closeStocktaking($record);

                    Notification::make()
                        ->title('تم إغلاق الجرد بنجاح')
                        ->body('تم تحديث كميات المخزون بناءً على الكميات الفعلية')
                        ->success()
                        ->send();

                } catch (Exception $e) {
                    Notification::make()
                        ->title('فشل في إغلاق الجرد')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function table(?string $name = null): Action
    {
        return Action::make($name ?? 'close')
            ->label('إغلاق')
            ->icon('heroicon-o-lock-closed')
            ->color('success')
            ->visible(fn (Stocktaking $record): bool => is_null($record->closed_at))
            ->requiresConfirmation()
            ->modalHeading('إغلاق الجرد')
            ->modalDescription(fn (Stocktaking $record): string|HtmlString => static::getModalDescription($record))
            ->action(function (Stocktaking $record) {
                try {
                    $stocktakingService = app(StocktakingService::class);
                    $stocktakingService->closeStocktaking($record);

                    Notification::make()
                        ->title('تم إغلاق الجرد بنجاح')
                        ->body('تم تحديث كميات المخزون بناءً على الكميات الفعلية')
                        ->success()
                        ->send();
                } catch (Exception $e) {
                    Notification::make()
                        ->title('فشل في إغلاق الجرد')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getModalDescription(Stocktaking $record): string|HtmlString
    {
        $baseDescription = 'هل أنت متأكد من إغلاق هذا الجرد؟ سيتم تحديث كميات المخزون بناءً على الكميات الفعلية ولن يمكن تعديل الجرد بعد ذلك.';

        $stocktakingService = app(StocktakingService::class);
        $changedItems = $stocktakingService->getChangedItemsSinceCount($record);
        $hasOpenShift = app(ShiftService::class)->getCurrentShift() !== null;

        if (empty($changedItems) && ! $hasOpenShift) {
            return $baseDescription;
        }

        $warnings = [];

        if (! empty($changedItems)) {
            $count = count($changedItems);
            $exampleSlice = array_slice($changedItems, 0, 5);
            $exampleStrings = array_map(function (array $item): string {
                return e($item['name']).': عند الجرد '.static::formatQuantity($item['snapshot']).' ← الآن '.static::formatQuantity($item['current']);
            }, $exampleSlice);

            $examplesText = implode('، ', $exampleStrings);
            if ($count > 5) {
                $examplesText .= '، ...';
            }

            $warnings[] = "⚠️ تنبيه: تغيّر رصيد {$count} صنف منذ إدخال الجرد (مثال: {$examplesText}). سيتم حساب الفرق مقابل الرصيد الحالي وقت الإغلاق، والمستخدم مسؤول عن صحة الكميات.";
        }

        if ($hasOpenShift) {
            $shiftWarning = 'يوجد شيفت مفتوح حالياً، وقد تتغير الكميات أثناء الإغلاق.';
            if (empty($warnings)) {
                $warnings[] = "⚠️ تنبيه: {$shiftWarning}";
            } else {
                $warnings[] = $shiftWarning;
            }
        }

        return new HtmlString($baseDescription.'<br><br>'.implode('<br>', $warnings));
    }

    public static function formatQuantity(float|int|string $value): string
    {
        $formatted = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
    }
}
