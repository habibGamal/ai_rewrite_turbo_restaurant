<?php

namespace App\Filament\Resources\Equipment;

use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Models\Equipment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Equipment catalogue (معدات): name, price per use and cost per use. Which
 * order types may use each one is set here or in the order type panel.
 * Behind the EQUIPMENT feature (see ModuleScreens::featureMap()).
 */
class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'equipment';

    public static function getModelLabel(): string
    {
        return 'معدة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المعدات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),

                Grid::make(2)
                    ->schema([
                        TextInput::make('usage_price')
                            ->label('سعر الاستخدام')
                            ->helperText('يُضاف لفاتورة الطلب عن كل مرة استخدام.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999)
                            ->required()
                            ->suffix('جنيه'),

                        TextInput::make('usage_cost')
                            ->label('تكلفة الاستخدام')
                            ->helperText('تُخصم من ربح الطلب (فحم، كهرباء، ...).')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999)
                            ->required()
                            ->default(0)
                            ->suffix('جنيه'),
                    ]),

                Grid::make(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),

                        TextInput::make('sort_order')
                            ->label('الترتيب')
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                    ]),

                CheckboxList::make('orderTypes')
                    ->label('أنواع الطلبات المسموح بها')
                    ->helperText('لا يظهر زر «إضافة معدات» إلا في أنواع الطلبات المختارة.')
                    ->relationship('orderTypes', 'label', fn ($query) => $query->where('behavior', '!=', 'instant')->orderBy('id'))
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('usage_price')
                    ->label('سعر الاستخدام')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('usage_cost')
                    ->label('تكلفة الاستخدام')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('orderTypes.label')
                    ->label('أنواع الطلبات')
                    ->badge(),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Equipment $record): bool => ! $record->orderLines()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }
}
