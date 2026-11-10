<?php

namespace App\Filament\Actions;

use App\Exceptions\ManufacturingException;
use App\Exceptions\StockOperationException;
use App\Filament\Resources\ManufacturingOrders\ManufacturingOrderResource;
use App\Models\ManufacturingOrder;
use App\Services\Manufacturing\ManufacturingOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class CompleteManufacturingOrderAction
{
    public static function make(): Action
    {
        return Action::make('complete')->label('إكمال التصنيع')->icon('heroicon-o-check-circle')
            ->visible(fn (ManufacturingOrder $record): bool => ManufacturingOrderResource::canEdit($record))
            ->schema(fn (ManufacturingOrder $record): array => $record->outputs()->with('product')->get()->map(fn ($output) => TextInput::make('actual_outputs.'.$output->id)
                ->label($output->product->name.($output->is_main ? ' (رئيسي)' : ''))
                ->numeric()->step(0.001)->minValue($output->is_main ? 0.001 : 0)->required()
            )->all())
            ->fillForm(fn (ManufacturingOrder $record): array => [
                'actual_outputs' => $record->outputs()->pluck('expected_quantity', 'id')->all(),
            ])
            ->modalHeading('إكمال التصنيع')->modalSubmitActionLabel('إكمال التصنيع')
            ->modalDescription('أدخل الكميات الفعلية. سيتم سحب المدخلات المسجلة وإضافة المخرجات وإغلاق الأمر.')
            ->action(function (ManufacturingOrder $record, array $data, Action $action, $livewire): void {
                abort_unless(ManufacturingOrderResource::canEdit($record), 403);
                if ($livewire instanceof \App\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder) {
                    $livewire->save(false, false);
                }
                try {
                    app(ManufacturingOrderService::class)->complete($record, $data['actual_outputs']);
                } catch (ManufacturingException|StockOperationException $exception) {
                    Notification::make()->title('تعذر إكمال التصنيع')->body($exception->getMessage())->danger()->send();
                    $action->halt();
                }
                Notification::make()->title('تم إكمال التصنيع بنجاح')->success()->send();
                $livewire->redirect(ManufacturingOrderResource::getUrl('view', ['record' => $record]));
            });
    }
}
