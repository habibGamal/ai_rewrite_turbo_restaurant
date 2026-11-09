<?php

namespace App\Filament\Resources\OrderTypeRows\Pages;

use App\Enums\OrderBehavior;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderTypeRows\OrderTypeRowResource;
use App\Models\Order;
use App\Models\OrderTypeRow;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOrderTypeRow extends EditRecord
{
    protected static string $resource = OrderTypeRowResource::class;

    protected function getHeaderActions(): array
    {
        /** @var OrderTypeRow $record */
        $record = $this->getRecord();

        if ($record->is_system) {
            return [];
        }

        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var OrderTypeRow $record */
        $record = $this->getRecord();

        $data['code'] = $record->code;

        if ($record->is_system) {
            $data['behavior'] = $record->behavior->value;

            return $data;
        }

        $behavior = OrderBehavior::tryFrom((string) ($data['behavior'] ?? ''));

        if ($behavior !== null && $behavior !== $record->behavior && $this->hasOpenOrders($record->code)) {
            throw ValidationException::withMessages([
                'data.behavior' => 'لا يمكن تغيير سلوك نوع له طلبات مفتوحة',
            ]);
        }

        return $data;
    }

    private function hasOpenOrders(string $code): bool
    {
        return Order::query()
            ->where('type', $code)
            ->whereIn('status', [
                OrderStatus::PROCESSING->value,
                OrderStatus::PENDING->value,
                OrderStatus::OUT_FOR_DELIVERY->value,
            ])
            ->exists();
    }
}
