<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Services\OrderHistoryService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderTimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'الخط الزمني للطلب';

    protected static ?string $modelLabel = 'إجراء';

    protected static ?string $pluralModelLabel = 'الخط الزمني';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        // Sync historical events for this order if none exist
        $owner = $this->getOwnerRecord();
        if ($owner instanceof Order) {
            app(OrderHistoryService::class)->syncOrderHistory($owner);
        }

        return $table
            ->recordTitleAttribute('action_name')
            ->columns([
                TextColumn::make('created_at')
                    ->label('الوقت والتاريخ')
                    ->dateTime('Y-m-d H:i:s')
                    ->description(fn (OrderHistory $record): string => $record->created_at ? $record->created_at->diffForHumans() : '')
                    ->sortable(),

                TextColumn::make('action_name')
                    ->label('نوع الإجراء')
                    ->badge()
                    ->color(fn (OrderHistory $record): string => match ($record->action) {
                        'created', 'completed', 'payment_added' => 'success',
                        'items_added' => 'info',
                        'items_removed', 'cancelled', 'return_created' => 'danger',
                        'items_modified', 'discount_applied' => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (OrderHistory $record): string => match ($record->action) {
                        'created' => 'heroicon-o-plus-circle',
                        'completed' => 'heroicon-o-check-circle',
                        'payment_added' => 'heroicon-o-banknotes',
                        'items_added' => 'heroicon-o-shopping-bag',
                        'items_removed' => 'heroicon-o-trash',
                        'items_modified' => 'heroicon-o-pencil-square',
                        'cancelled' => 'heroicon-o-x-circle',
                        'return_created' => 'heroicon-o-arrow-uturn-left',
                        'discount_applied' => 'heroicon-o-tag',
                        'customer_linked' => 'heroicon-o-user',
                        'driver_linked' => 'heroicon-o-truck',
                        default => 'heroicon-o-clock',
                    }),

                TextColumn::make('description')
                    ->label('التفاصيل')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('المستخدم / الكاشير')
                    ->placeholder('النظام')
                    ->icon('heroicon-o-user')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('نوع الإجراء')
                    ->options([
                        'created' => 'إنشاء الطلب',
                        'items_added' => 'إضافة أصناف',
                        'items_removed' => 'حذف أصناف',
                        'items_modified' => 'تعديل أصناف',
                        'payment_added' => 'سداد دفعة',
                        'completed' => 'إتمام الطلب',
                        'cancelled' => 'إلغاء الطلب',
                        'discount_applied' => 'تطبيق خصم',
                        'customer_linked' => 'ربط عميل',
                        'driver_linked' => 'ربط سائق',
                        'notes_updated' => 'تحديث ملاحظات',
                    ]),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
