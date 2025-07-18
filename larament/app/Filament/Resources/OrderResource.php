<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'المشاهدة فقط';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطلبات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('السائق')
                    ->searchable()
                    ->sortable()
                    ->placeholder('لا يوجد'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('الكاشير')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'preparing',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'في الانتظار',
                        'preparing' => 'قيد التحضير',
                        'completed' => 'مكتمل',
                        'cancelled' => 'ملغى',
                        default => $state,
                    }),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('النوع')
                    ->colors([
                        'primary' => 'dine_in',
                        'warning' => 'takeaway',
                        'success' => 'delivery',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'dine_in' => 'في المطعم',
                        'takeaway' => 'استلام',
                        'delivery' => 'توصيل',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('total')
                    ->label('المجموع الكلي')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('profit')
                    ->label('الربح')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('payment_status')
                    ->label('حالة الدفع')
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partial',
                        'success' => 'paid',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unpaid' => 'غير مدفوع',
                        'partial' => 'دفع جزئي',
                        'paid' => 'مدفوع',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('dine_table_number')
                    ->label('رقم الطاولة')
                    ->placeholder('لا يوجد'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'في الانتظار',
                        'preparing' => 'قيد التحضير',
                        'completed' => 'مكتمل',
                        'cancelled' => 'ملغى',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->label('النوع')
                    ->options([
                        'dine_in' => 'في المطعم',
                        'takeaway' => 'استلام',
                        'delivery' => 'توصيل',
                    ]),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options([
                        'unpaid' => 'غير مدفوع',
                        'partial' => 'دفع جزئي',
                        'paid' => 'مدفوع',
                    ]),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('العميل')
                    ->options(Customer::all()->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('driver_id')
                    ->label('السائق')
                    ->options(Driver::all()->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('الكاشير')
                    ->options(User::all()->pluck('name', 'id'))
                    ->searchable(),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
