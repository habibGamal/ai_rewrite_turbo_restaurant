<?php

namespace App\Modules\Restaurant\Filament\Resources\TableTypes\Pages;

use App\Modules\Restaurant\Filament\Resources\TableTypes\TableTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTableTypes extends ListRecords
{
    protected static string $resource = TableTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إنشاء نوع طاولة جديد'),
        ];
    }

    public function getTitle(): string
    {
        return 'أنواع الطاولات';
    }
}
