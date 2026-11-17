<?php

namespace App\Filament\Resources\Terminals\Pages;

use App\Filament\Resources\Terminals\TerminalResource;
use App\Models\Terminal;
use App\Models\TerminalSession;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditTerminal extends EditRecord
{
    protected static string $resource = TerminalResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Terminal $record */
        $record = $this->getRecord();
        if ($record->is_default) {
            // The default sales point carries web orders and back-office money: never off.
            $data['is_active'] = true;
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $record->is_active
            && TerminalSession::query()->where('terminal_id', $record->getKey())->open()->exists()) {
            throw ValidationException::withMessages([
                'data.is_active' => 'لا يمكن إيقاف منفذ بيع مفتوح. أغلقه أولاً.',
            ]);
        }

        TerminalResource::assertWithinLimit((bool) ($data['is_active'] ?? $record->is_active), $record);

        return $data;
    }
}
