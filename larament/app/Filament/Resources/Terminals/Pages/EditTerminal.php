<?php

namespace App\Filament\Resources\Terminals\Pages;

use App\Filament\Resources\Terminals\TerminalResource;
use App\Models\Terminal;
use Filament\Resources\Pages\EditRecord;

class EditTerminal extends EditRecord
{
    protected static string $resource = TerminalResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Terminal $record */
        $record = $this->getRecord();
        TerminalResource::assertWithinLimit((bool) ($data['is_active'] ?? false), $record);

        return $data;
    }
}
