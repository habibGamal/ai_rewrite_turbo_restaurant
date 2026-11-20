<?php

namespace App\Filament\Resources\Terminals\Pages;

use App\Filament\Resources\Terminals\TerminalResource;
use App\Models\Terminal;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTerminals extends ListRecords
{
    protected static string $resource = TerminalResource::class;

    public function getSubheading(): ?string
    {
        return 'المفعل '.Terminal::query()->active()->count().' من '.TerminalResource::maxActive().' منافذ بيع مسموحة.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
