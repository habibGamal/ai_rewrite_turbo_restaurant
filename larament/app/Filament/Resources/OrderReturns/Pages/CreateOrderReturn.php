<?php

namespace App\Filament\Resources\OrderReturns\Pages;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use Exception;
use App\Filament\Resources\OrderReturns\OrderReturnResource;
use App\Services\Orders\OrderReturnService;
use App\Services\Resources\OrderReturnCalculatorService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateOrderReturn extends CreateRecord
{
    protected static string $resource = OrderReturnResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filter out items with zero quantity
        if (isset($data['return_items'])) {
            $data['return_items'] = collect($data['return_items'])
                ->filter(fn($item) => ($item['quantity'] ?? 0) > 0)
                ->map(fn($item) => OrderReturnCalculatorService::prepareReturnItemData($item))
                ->values()
                ->toArray();
        }

        // Filter out refunds with zero amount
        if (isset($data['refund_distribution'])) {
            $data['refund_distribution'] = collect($data['refund_distribution'])
                ->filter(fn($refund) => ($refund['amount'] ?? 0) > 0)
                ->values()
                ->toArray();
        }

        // Set user and shift
        $data['user_id'] = auth()->id();
        $data['shift_id'] = session('current_shift_id');

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $returnService = app(OrderReturnService::class);

            // Get the order
            $order = Order::findOrFail($data['order_id']);

            // Prepare return items
            $returnItems = collect($data['return_items'])
                ->map(fn($item) => [
                    'order_item_id' => $item['order_item_id'],
                    'quantity' => $item['quantity'],
                    'refund_amount' => $item['refund_amount'],
                ])
                ->toArray();

            // Prepare refund distribution
            $refundDistribution = collect($data['refund_distribution'])
                ->map(fn($refund) => [
                    'method' => $refund['method'],
                    'amount' => $refund['amount'],
                ])
                ->toArray();

            // Validate
            if (empty($returnItems)) {
                throw new Exception('يجب تحديد صنف واحد على الأقل للإرجاع');
            }

            if (empty($refundDistribution)) {
                throw new Exception('يجب تحديد طريقة استرجاع واحدة على الأقل');
            }

            // Process the return
            $orderReturn = $returnService->processReturn(
                order: $order,
                returnItems: $returnItems,
                reason: $data['reason'],
                refundDistribution: $refundDistribution,
                shiftId: $data['shift_id'] ?? $order->shift_id,
                reverseStock: $data['reverse_stock'] ?? true
            );

            Notification::make()
                ->title('تم إرجاع الطلب بنجاح')
                ->body("تم إرجاع أصناف بقيمة {$orderReturn->total_refund} ج.م")
                ->success()
                ->send();

            return $orderReturn;

        } catch (Exception $e) {
            Notification::make()
                ->title('فشل في إرجاع الطلب')
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw $e;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
