<?php

namespace App\Filament\Resources\Customers;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\Textarea;
use Filament\Actions\ImportAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers;
use App\Filament\Traits\AdminAccess;
use App\Filament\Imports\CustomerImporter;
use App\Models\Customer;
use App\Models\Region;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Customer::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'العملاء';

    protected static ?string $modelLabel = 'عميل';

    protected static ?string $pluralModelLabel = 'العملاء';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم العميل')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('أدخل اسم العميل'),

                        TextInput::make('phone')
                            ->label('رقم الهاتف')
                            ->tel()
                            ->required()
                            ->maxLength(20)
                            ->placeholder('أدخل رقم الهاتف')
                            ->unique(Customer::class, 'phone', ignoreRecord: true)
                            ->helperText('يجب أن يكون رقم الهاتف فريداً'),

                        Toggle::make('has_whatsapp')
                            ->label('لديه واتساب')
                            ->default(false)
                            ->inline(false),

                        Select::make('region')
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

                        TextInput::make('delivery_cost')
                            ->label('تكلفة التوصيل')
                            ->numeric()
                            ->step(0.01)
                            ->placeholder('0.00')
                            ->prefix('ج.م')
                            ->helperText('سيتم تحديثها تلقائياً عند اختيار المنطقة'),
                    ]),

                Textarea::make('address')
                    ->label('العنوان')
                    ->maxLength(500)
                    ->rows(3)
                    ->placeholder('أدخل عنوان العميل'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions([
                ImportAction::make()
                    ->importer(CustomerImporter::class)
                    ->label('استيراد عملاء')
                    ->modalHeading('استيراد عملاء من ملف CSV')
                    ->modalSubmitActionLabel('استيراد')
                    ->modalCancelActionLabel('إلغاء')
                    ->color('success')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->maxRows(10000)
                    ->chunkSize(100),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label('اسم العميل')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('phone')
                    ->label('رقم الهاتف')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                IconColumn::make('has_whatsapp')
                    ->label('واتساب')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('region')
                    ->label('المنطقة')
                    ->searchable()
                    ->sortable()
                    ->placeholder('غير محدد'),

                TextColumn::make('delivery_cost')
                    ->label('تكلفة التوصيل')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('address')
                    ->label('العنوان')
                    ->limit(50)
                    ->placeholder('غير محدد')
                    ->tooltip(function ($record) {
                        return $record->address ?: 'غير محدد';
                    }),

                TextColumn::make('orders_count')
                    ->label('عدد الطلبات')
                    ->counts('orders')
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('has_whatsapp')
                    ->label('لديه واتساب')
                    ->boolean()
                    ->trueLabel('نعم')
                    ->falseLabel('لا')
                    ->placeholder('الكل'),

                SelectFilter::make('region')
                    ->label('المنطقة')
                    ->options(function () {
                        return Region::pluck('name', 'name');
                    })
                    ->placeholder('جميع المناطق'),

                Filter::make('delivery_cost')
                    ->label('تكلفة التوصيل')
                    ->schema([
                        TextInput::make('delivery_cost_from')
                            ->label('من')
                            ->numeric()
                            ->prefix('ج.م'),
                        TextInput::make('delivery_cost_to')
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

                Filter::make('orders_count')
                    ->label('عدد الطلبات')
                    ->schema([
                        TextInput::make('orders_count_from')
                            ->label('أكثر من')
                            ->numeric(),
                        TextInput::make('orders_count_to')
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
            ->recordActions([
                ViewAction::make()->label('عرض'),
                EditAction::make()->label('تعديل'),
                DeleteAction::make()
                    ->label('حذف')
                    ->requiresConfirmation()
                    ->modalHeading('حذف العميل')
                    ->modalDescription('هل أنت متأكد من حذف هذا العميل؟ لن تتمكن من التراجع عن هذا الإجراء.')
                    ->modalSubmitActionLabel('نعم، احذف')
                    ->modalCancelActionLabel('إلغاء')
                    ->before(function ($record) {
                        // Check if customer has orders
                        if ($record->orders()->exists()) {
                            throw new Exception('لا يمكن حذف العميل لوجود طلبات مرتبطة به');
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
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
                                throw new Exception('لا يمكن حذف بعض العملاء لوجود طلبات مرتبطة بهم');
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
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
