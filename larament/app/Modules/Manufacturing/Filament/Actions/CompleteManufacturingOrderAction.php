<?php

namespace App\Modules\Manufacturing\Filament\Actions;

use App\Exceptions\ManufacturingException;
use App\Exceptions\StockOperationException;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use App\Modules\Manufacturing\Services\ManufacturingOrderService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class CompleteManufacturingOrderAction
{
    public static function make(): Action
    {
        return Action::make('complete')
            ->label('إكمال التصنيع')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('إكمال التصنيع')
            ->modalDescription('سيتم سحب المدخلات الفعلية وإضافة المخرجات الفعلية وإغلاق الأمر.')
            ->modalSubmitActionLabel('إكمال التصنيع')
            ->visible(fn (ManufacturingOrder $record): bool => ManufacturingOrderResource::canEdit($record))
            ->action(function (ManufacturingOrder $record, Action $action, $livewire): void {
                abort_unless(ManufacturingOrderResource::canEdit($record), 403);

                if (method_exists($livewire, 'save')) {
                    $livewire->save(shouldRedirect: false);
                    $record->refresh();
                }

                $quantities = $record->outputs()->pluck('actual_quantity', 'id')->all();

                try {
                    app(ManufacturingOrderService::class)->complete($record, $quantities);
                } catch (ManufacturingException|StockOperationException $exception) {
                    Notification::make()->title('تعذر إكمال التصنيع')->body($exception->getMessage())->danger()->send();
                    $action->halt();
                } catch (ValidationException $exception) {
                    Notification::make()->title('تعذر إكمال التصنيع')->body($exception->validator->errors()->first())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('تم إكمال التصنيع بنجاح')->success()->send();

                if (method_exists($livewire, 'redirect')) {
                    $livewire->redirect(ManufacturingOrderResource::getUrl('view', ['record' => $record]));
                }
            });
    }
}
