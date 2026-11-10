<?php

namespace App\Filament\Resources\ManufacturingOrders;

use App\Enums\ProductType;
use App\Filament\Actions\CompleteManufacturingOrderAction;
use App\Filament\Resources\ManufacturingOrders\Pages\CreateManufacturingOrder;
use App\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder;
use App\Filament\Resources\ManufacturingOrders\Pages\ListManufacturingOrders;
use App\Filament\Resources\ManufacturingOrders\Pages\ViewManufacturingOrder;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingOrderInput;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ManufacturingOrderResource extends Resource
{
    protected static ?string $model = ManufacturingOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'أوامر التصنيع';

    protected static ?string $modelLabel = 'أمر تصنيع';

    protected static ?string $pluralModelLabel = 'أوامر التصنيع';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function canEdit(Model $record): bool
    {
        return $record instanceof ManufacturingOrder && $record->closed_at === null && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof ManufacturingOrder && $record->closed_at === null && parent::canDelete($record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات أمر التصنيع')->schema([
                Select::make('manufacturing_recipe_id')->label('وصفة التصنيع')
                    ->options(fn () => ManufacturingRecipe::active()->pluck('name', 'id'))
                    ->searchable()->required()->disabledOn('edit'),
                Select::make('source_warehouse_id')->label('مخزن المصدر')
                    ->options(fn () => Warehouse::active()->pluck('name', 'id'))
                    ->default(fn () => Warehouse::default()->id)->required()->disabledOn('edit'),
                Select::make('destination_warehouse_id')->label('مخزن الوجهة')
                    ->options(fn () => Warehouse::active()->pluck('name', 'id'))
                    ->default(fn () => Warehouse::default()->id)->required()->disabledOn('edit'),
                TextInput::make('planned_main_quantity')->label('كمية المخرج الرئيسي المخططة')
                    ->numeric()->step(0.001)->minValue(0.001)->required()->disabledOn('edit'),
                Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
            ])->columns(2),
            Section::make('المدخلات')->hiddenOn('create')->schema([
                Repeater::make('inputs')->label('الخامات والمستهلكات')->relationship('inputs')
                    ->minItems(1)->reorderable(false)->schema([
                        Select::make('product_id')->label('المنتج')
                            ->options(fn () => Product::whereIn('type', [ProductType::RawMaterial, ProductType::Consumable])->pluck('name', 'id'))
                            ->searchable()->required()
                            ->disabled(fn (?ManufacturingOrderInput $record) => $record !== null),
                        TextInput::make('planned_quantity')->label('الكمية المخططة')->disabled()->dehydrated(false),
                        TextInput::make('actual_quantity')->label('الكمية الفعلية')->numeric()->step(0.001)->minValue(0)->required(),
                    ])->columns(3)
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'planned_quantity' => 0]),
            ]),
            Section::make('المخرجات المتوقعة من الوصفة')->hiddenOn('create')->schema([
                RepeatableEntry::make('outputs')->label('المخرجات')->schema([
                    TextEntry::make('product.name')->label('المنتج'),
                    TextEntry::make('expected_quantity')->label('الكمية المتوقعة'),
                    TextEntry::make('cost_method')->label('طريقة التكلفة')
                        ->formatStateUsing(fn ($state) => $state === 'fixed' ? 'ثابت لكل وحدة' : 'نسبة مئوية'),
                    TextEntry::make('cost_value')->label('قيمة التكلفة'),
                ])->columns(4),
            ]),
        ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات أمر التصنيع')->schema([
                TextEntry::make('id')->label('رقم الأمر'),
                TextEntry::make('recipe.name')->label('الوصفة'),
                TextEntry::make('sourceWarehouse.name')->label('مخزن المصدر'),
                TextEntry::make('destinationWarehouse.name')->label('مخزن الوجهة'),
                TextEntry::make('planned_main_quantity')->label('كمية المخرج الرئيسي المخططة'),
                TextEntry::make('total_input_cost')->label('إجمالي تكلفة المدخلات')->money('EGP'),
                TextEntry::make('user.name')->label('أنشأه'),
                TextEntry::make('closedBy.name')->label('أكمله'),
                TextEntry::make('closed_at')->label('تاريخ الإكمال')->dateTime()->placeholder('مسودة'),
                TextEntry::make('business_date')->label('يوم العمل')->date(),
                TextEntry::make('notes')->label('ملاحظات'),
            ])->columns(3),
            Section::make('المدخلات')->schema([
                RepeatableEntry::make('inputs')->label('الخامات والمستهلكات')->schema([
                    TextEntry::make('product.name')->label('المنتج'),
                    TextEntry::make('planned_quantity')->label('المخطط'),
                    TextEntry::make('actual_quantity')->label('الفعلي'),
                    TextEntry::make('unit_cost')->label('تكلفة الوحدة (ج.م)')->numeric(4),
                    TextEntry::make('total_cost')->label('التكلفة الإجمالية')->money('EGP'),
                ])->columns(5),
            ]),
            Section::make('المخرجات والانحراف')->schema([
                RepeatableEntry::make('outputs')->label('المخرجات')->schema([
                    TextEntry::make('product.name')->label('المنتج'),
                    TextEntry::make('is_main')->label('نوع المخرج')->formatStateUsing(fn ($state) => $state ? 'رئيسي' : 'إضافي'),
                    TextEntry::make('expected_quantity')->label('المتوقع'),
                    TextEntry::make('actual_quantity')->label('الفعلي'),
                    TextEntry::make('allocated_cost')->label('التكلفة المخصصة')->money('EGP'),
                    TextEntry::make('unit_cost')->label('تكلفة الوحدة (ج.م)')->numeric(4),
                    TextEntry::make('variance_quantity')->label('انحراف الكمية'),
                    TextEntry::make('variance_value')->label('قيمة الانحراف')->money('EGP'),
                ])->columns(4),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الأمر')->sortable(),
            TextColumn::make('recipe.name')->label('الوصفة')->searchable(),
            TextColumn::make('sourceWarehouse.name')->label('المصدر'),
            TextColumn::make('destinationWarehouse.name')->label('الوجهة'),
            TextColumn::make('planned_main_quantity')->label('الكمية المخططة'),
            TextColumn::make('total_input_cost')->label('تكلفة المدخلات')->money('EGP'),
            TextColumn::make('closed_at')->label('الإكمال')->dateTime()->placeholder('مسودة'),
        ])->filters([
            TernaryFilter::make('closed_at')->label('مكتمل')->nullable(),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make()->visible(fn (ManufacturingOrder $record) => static::canEdit($record)),
            CompleteManufacturingOrderAction::make(),
            DeleteAction::make()->visible(fn (ManufacturingOrder $record) => static::canDelete($record)),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManufacturingOrders::route('/'),
            'create' => CreateManufacturingOrder::route('/create'),
            'view' => ViewManufacturingOrder::route('/{record}'),
            'edit' => EditManufacturingOrder::route('/{record}/edit'),
        ];
    }
}
