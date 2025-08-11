<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Models\Customer;
use App\Models\Region;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Grid;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'العملاء';

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم العميل')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('أدخل اسم العميل'),

                        Forms\Components\TextInput::make('phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->placeholder('أدخل رقم الهاتف')
                            ->unique(Customer::class, 'phone', ignoreRecord: true)
                            ->helperText('يجب أن يكون رقم الهاتف فريداً'),

                        Forms\Components\Toggle::make('has_whatsapp')
                            ->label('لديه واتساب')
                            ->default(false)
                            ->inline(false),

                        Forms\Components\Select::make('region')
                            ->label('المنطقة')
                            ->options(function () {
                                return Region::pluck('name', 'name');
                            })
                            ->searchable()
                            ->placeholder('اختر المنطقة')
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $region = Region::where('name', $state)->first();
                                    if ($region) {
                                        $set('delivery_cost', $region->delivery_cost);
                                    }
                                }
                            }),

                        Forms\Components\TextInput::make('delivery_cost')
                            ->label('تكلفة التوصيل')
                            ->numeric()
                            ->step(0.01)
                            ->placeholder('0.00')
                            ->prefix('ج.م')
                            ->helperText('سيتم تحديثها تلقائياً عند اختيار المنطقة'),
                    ]),

                Forms\Components\Textarea::make('address')
                    ->label('العنوان')
                    ->maxLength(500)
                    ->rows(3)
                    ->placeholder('أدخل عنوان العميل'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\IconColumn::make('has_whatsapp')
                    ->label('واتساب')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('region')
                    ->label('المنطقة')
                    ->searchable()
                    ->sortable()
                    ->placeholder('غير محدد'),

                Tables\Columns\TextColumn::make('delivery_cost')
                    ->label('تكلفة التوصيل')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('address')
                    ->label('العنوان')
                    ->limit(50)
                    ->placeholder('غير محدد')
                    ->tooltip(function ($record) {
                        return $record->address ?: 'غير محدد';
                    }),

                Tables\Columns\TextColumn::make('orders_count')
                    ->label('عدد الطلبات')
                    ->counts('orders')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('has_whatsapp')
                    ->label('لديه واتساب')
                    ->boolean()
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->placeholder('الكل'),

                Tables\Filters\SelectFilter::make('region')
                    ->label('المنطقة')
                    ->options(function () {
                        return Region::pluck('name', 'name');
                    })
                    ->placeholder('جميع المناطق'),

                Tables\Filters\Filter::make('delivery_cost')
                    ->label('تكلفة التوصيل')
                    ->form([
                        Forms\Components\TextInput::make('delivery_cost_from')
                            ->label('من')
                            ->numeric()
                            ->prefix('ج.م'),
                        Forms\Components\TextInput::make('delivery_cost_to')
                            ->label('إلى')
                            ->numeric()
                            ->prefix('ج.م'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['delivery_cost_from'],
                                fn (Builder $query, $cost): Builder => $query->where('delivery_cost', '>=', $cost),
                            )
                            ->when(
                                $data['delivery_cost_to'],
                                fn (Builder $query, $cost): Builder => $query->where('delivery_cost', '<=', $cost),
                            );
                    }),

                Tables\Filters\Filter::make('orders_count')
                    ->label('عدد الطلبات')
                    ->form([
                        Forms\Components\TextInput::make('orders_count_from')
                            ->label('أكثر من')
                            ->numeric(),
                        Forms\Components\TextInput::make('orders_count_to')
                            ->label('أقل من')
                            ->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->withCount('orders')
                            ->when(
                                $data['orders_count_from'],
                                fn (Builder $query, $count): Builder => $query->having('orders_count', '>=', $count),
                            )
                            ->when(
                                $data['orders_count_to'],
                                fn (Builder $query, $count): Builder => $query->having('orders_count', '<=', $count),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('عرض'),
                Tables\Actions\EditAction::make()->label('تعديل'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalHeading('حذف العميل')
                    ->modalDescription('هل أنت متأكد من حذف هذا العميل؟ لن تتمكن من التراجع عن هذا الإجراء.')
                    ->modalSubmitActionLabel('نعم، احذف')
                    ->modalCancelActionLabel('إلغاء')
                    ->before(function ($record) {
                        // Check if customer has orders
                        if ($record->orders()->exists()) {
                            throw new \Exception('لا يمكن حذف العميل لوجود طلبات مرتبطة به');
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('حذف المحدد')
                        ->requiresConfirmation()
                        ->modalHeading('حذف العملاء المحددين')
                        ->modalDescription('هل أنت متأكد من حذف العملاء المحددين؟ لن تتمكن من التراجع عن هذا الإجراء.')
                        ->modalSubmitActionLabel('نعم، احذف')
                        ->modalCancelActionLabel('إلغاء')
                        ->before(function ($records) {
                            // Check if any customer has orders
                            $customersWithOrders = $records->filter(function ($customer) {
                                return $customer->orders()->exists();
                            });

                            if ($customersWithOrders->count() > 0) {
                                throw new \Exception('لا يمكن حذف بعض العملاء لوجود طلبات مرتبطة بهم');
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('orders');
    }
}
