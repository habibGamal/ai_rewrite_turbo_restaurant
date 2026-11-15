<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Orders\OrderTypeCatalog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CashierOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'الطلبات المنشأة بواسطة الكاشير';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->columns([
                TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label('نوع الطلب')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->label)
                    ->color(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->color)
                    ->icon(fn (mixed $state): string => app(OrderTypeCatalog::class)->display((string) $state)->icon)
                    ->sortable(),

                TextColumn::make('status')
                    ->label('حالة الطلب')
                    ->badge()
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->badge()
                    ->sortable(),

                TextColumn::make('sub_total')
                    ->label('المجموع الفرعي')
                    ->money('EGP')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('discount')
                    ->label('الخصم')
                    ->money('EGP')
                    ->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('dine_table_number')
                    ->label('الطاولة')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع الطلب')
                    ->options(fn (): array => app(OrderTypeCatalog::class)->options()),

                SelectFilter::make('status')
                    ->label('حالة الطلب')
                    ->options(OrderStatus::class),

                SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options(PaymentStatus::class),

                Filter::make('has_discount')
                    ->label('الطلبات بخصم فقط')
                    ->query(fn (Builder $query): Builder => $query->where('discount', '>', 0)),

                Filter::make('no_discount')
                    ->label('الطلبات بدون خصم فقط')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->where('discount', '=', 0)->orWhereNull('discount'))),

                Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->schema([
                        DatePicker::make('created_from')->label('من تاريخ'),
                        DatePicker::make('created_until')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['created_until'], fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Order $record): string => url("/admin/orders/{$record->id}")),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
