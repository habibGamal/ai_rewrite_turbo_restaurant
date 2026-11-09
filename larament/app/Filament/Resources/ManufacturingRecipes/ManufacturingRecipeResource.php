<?php

namespace App\Filament\Resources\ManufacturingRecipes;

use App\Enums\ProductType;
use App\Filament\Resources\ManufacturingRecipes\Pages\CreateManufacturingRecipe;
use App\Filament\Resources\ManufacturingRecipes\Pages\EditManufacturingRecipe;
use App\Filament\Resources\ManufacturingRecipes\Pages\ListManufacturingRecipes;
use App\Filament\Resources\ManufacturingRecipes\Pages\ViewManufacturingRecipe;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ManufacturingRecipeResource extends Resource
{
    protected static ?string $model = ManufacturingRecipe::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'وصفات التصنيع';

    protected static ?string $modelLabel = 'وصفة تصنيع';

    protected static ?string $pluralModelLabel = 'وصفات التصنيع';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        $stockProductOptions = fn (): array => Product::query()
            ->whereIn('type', [
                ProductType::RawMaterial->value,
                ProductType::Consumable->value,
            ])
            ->pluck('name', 'id')
            ->all();

        return $schema
            ->components([
                Section::make('بيانات الوصفة')
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الوصفة')
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('المدخلات (الخامات والمستهلكات)')
                    ->schema([
                        Repeater::make('inputs')
                            ->label('المدخلات')
                            ->relationship('inputs')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->options($stockProductOptions)
                                    ->searchable()
                                    ->required(),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->step(0.001)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->collapsible(),
                    ]),

                Section::make('المخرجات')
                    ->schema([
                        Repeater::make('outputs')
                            ->label('المخرجات')
                            ->relationship('outputs')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->options($stockProductOptions)
                                    ->searchable()
                                    ->required(),

                                Toggle::make('is_main')
                                    ->label('مخرج رئيسي')
                                    ->default(false)
                                    ->live(),

                                TextInput::make('expected_quantity')
                                    ->label('الكمية المتوقعة')
                                    ->numeric()
                                    ->step(0.001)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required(),

                                Select::make('cost_method')
                                    ->label('طريقة التكلفة')
                                    ->options([
                                        'percent' => 'نسبة مئوية (%)',
                                        'fixed' => 'قيمة ثابتة لكل وحدة (ج.م)',
                                    ])
                                    ->default('percent')
                                    ->required(),

                                TextInput::make('cost_value')
                                    ->label('قيمة التكلفة')
                                    ->numeric()
                                    ->step(0.0001)
                                    ->minValue(0)
                                    ->default(0),
                            ])
                            ->columns(5)
                            ->collapsible(),
                    ]),
            ])
            ->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الوصفة')
                    ->schema([
                        TextEntry::make('id')
                            ->label('رقم الوصفة'),

                        TextEntry::make('name')
                            ->label('اسم الوصفة'),

                        IconEntry::make('is_active')
                            ->label('نشط')
                            ->boolean(),

                        TextEntry::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم الوصفة')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('اسم الوصفة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('mainOutput.product.name')
                    ->label('المخرج الرئيسي')
                    ->placeholder('-'),

                TextColumn::make('inputs_count')
                    ->label('عدد المدخلات')
                    ->counts('inputs')
                    ->sortable(),

                TextColumn::make('outputs_count')
                    ->label('عدد المخرجات')
                    ->counts('outputs')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManufacturingRecipes::route('/'),
            'create' => CreateManufacturingRecipe::route('/create'),
            'view' => ViewManufacturingRecipe::route('/{record}'),
            'edit' => EditManufacturingRecipe::route('/{record}/edit'),
        ];
    }
}
