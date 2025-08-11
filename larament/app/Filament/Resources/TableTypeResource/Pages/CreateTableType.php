<?php

namespace App\Filament\Resources\TableTypeResource\Pages;

use App\Filament\Resources\TableTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateTableType extends CreateRecord
{
    protected static string $resource = TableTypeResource::class;

    public function getTitle(): string
    {
        return 'إنشاء نوع طاولة جديد';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إنشاء نوع الطاولة بنجاح';
    }
}
