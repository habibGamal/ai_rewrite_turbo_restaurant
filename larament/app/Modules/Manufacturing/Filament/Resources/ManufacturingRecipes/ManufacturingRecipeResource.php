<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes;

use App\Enums\ProductType;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\CreateManufacturingRecipe;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\EditManufacturingRecipe;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\ListManufacturingRecipes;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingRecipes\Pages\ViewManufacturingRecipe;
use App\Filament\Traits\BelongsToModule;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Modules\Manufacturing\Services\ManufacturingCostPreviewService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder as FormPlaceholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn as FormTableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ManufacturingRecipeResource extends Resource
{
    use BelongsToModule;

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

                        Select::make('main_product_id')
                            ->label('المنتج الرئيسي')
                            ->options($stockProductOptions)
                            ->searchable()
                            ->required()
                            ->live(),

                        TextInput::make('batch_quantity')
                            ->label('كمية الدفعة')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(0.001)
                            ->default(1)
                            ->required()
                            ->helperText('الوصفة دي بتوصف دفعة واحدة: كام بتنتج من المنتج الرئيسي')
                            ->live(),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('المدخلات (لدفعة واحدة)')
                    ->schema([
                        Repeater::make('inputs')
                            ->label('المدخلات')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->table([
                                FormTableColumn::make('المنتج')->width('240px'),
                                FormTableColumn::make('الوحدة')->width('100px'),
                                FormTableColumn::make('الكمية')->width('120px'),
                                FormTableColumn::make('تكلفة الوحدة الحالية (ج.م)')->width('160px'),
                                FormTableColumn::make('تكلفة السطر (ج.م)')->width('160px'),
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->options($stockProductOptions)
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $product = $state ? Product::find($state) : null;
                                        $set('unit', $product ? Product::formatUnit($product->unit) : null);
                                        $cost = $product ? (float) $product->cost : 0.0;
                                        $set('unit_cost', $cost);
                                        $qty = (float) ($get('quantity') ?? 0);
                                        $set('line_cost', round($qty * $cost, 2));
                                    }),

                                TextInput::make('unit')
                                    ->label('الوحدة')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function ($record, $state, Get $get) {
                                        if ($state) {
                                            return $state;
                                        }
                                        $pid = $get('product_id') ?? $record?->product_id;
                                        $p = $pid ? Product::find($pid) : null;

                                        return $p ? Product::formatUnit($p->unit) : null;
                                    }),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->step(0.001)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $cost = (float) ($get('unit_cost') ?? 0);
                                        if ($cost <= 0) {
                                            $pid = $get('product_id');
                                            $p = $pid ? Product::find($pid) : null;
                                            $cost = (float) ($p->cost ?? 0);
                                            $set('unit_cost', $cost);
                                        }
                                        $qty = (float) ($state ?? 0);
                                        $set('line_cost', round($qty * $cost, 2));
                                    }),

                                TextInput::make('unit_cost')
                                    ->label('تكلفة الوحدة الحالية (ج.م)')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function ($record, $state, Get $get) {
                                        if ($state !== null && $state !== '') {
                                            return $state;
                                        }
                                        $pid = $get('product_id') ?? $record?->product_id;
                                        $p = $pid ? Product::find($pid) : null;

                                        return $p ? (float) $p->cost : 0.0;
                                    }),

                                TextInput::make('line_cost')
                                    ->label('تكلفة السطر (ج.م)')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function ($record, $state, Get $get) {
                                        if ($state !== null && $state !== '') {
                                            return $state;
                                        }
                                        $pid = $get('product_id') ?? $record?->product_id;
                                        $p = $pid ? Product::find($pid) : null;
                                        $cost = $p ? (float) $p->cost : 0.0;
                                        $qty = (float) ($get('quantity') ?? $record->quantity ?? 0);

                                        return round($qty * $cost, 2);
                                    }),
                            ]),

                        FormPlaceholder::make('total_batch_cost')
                            ->label('إجمالي تكلفة الدفعة')
                            ->content(function (Get $get): string {
                                $inputs = $get('inputs') ?? [];
                                $total = 0.0;
                                foreach ($inputs as $input) {
                                    $pid = $input['product_id'] ?? null;
                                    $qty = (float) ($input['quantity'] ?? 0);
                                    if ($pid && $qty > 0) {
                                        $p = Product::find($pid);
                                        if ($p) {
                                            $total += $qty * (float) $p->cost;
                                        }
                                    }
                                }

                                return number_format(round($total, 2), 2).' ج.م';
                            }),
                    ]),

                Section::make('مخرجات إضافية (اختياري)')
                    ->description('يتم أولاً خصم تكلفة المخرجات الثابتة (الكمية × السعر)، ثم تأخذ المخرجات بالنسبة المئوية نسبتها من التكلفة المتبقية، وأخيراً يحصل المنتج الرئيسي على كامل ما تبقى من تكلفة الدفعة.')
                    ->schema([
                        Repeater::make('extra_outputs')
                            ->label('المخرجات الإضافية')
                            ->defaultItems(0)
                            ->table([
                                FormTableColumn::make('المنتج')->width('220px'),
                                FormTableColumn::make('الوحدة')->width('100px'),
                                FormTableColumn::make('الكمية المتوقعة')->width('120px'),
                                FormTableColumn::make('طريقة توزيع التكلفة')->width('230px'),
                                FormTableColumn::make('القيمة')->width('120px'),
                                FormTableColumn::make('التكلفة المتوقعة للوحدة')->width('150px'),
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->options($stockProductOptions)
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        $product = $state ? Product::find($state) : null;
                                        $set('unit', $product ? Product::formatUnit($product->unit) : null);
                                    }),

                                TextInput::make('unit')
                                    ->label('الوحدة')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(function ($record, $state, Get $get) {
                                        if ($state) {
                                            return $state;
                                        }
                                        $pid = $get('product_id') ?? $record?->product_id;
                                        $p = $pid ? Product::find($pid) : null;

                                        return $p ? Product::formatUnit($p->unit) : null;
                                    }),

                                TextInput::make('expected_quantity')
                                    ->label('الكمية المتوقعة')
                                    ->numeric()
                                    ->step(0.001)
                                    ->minValue(0.001)
                                    ->default(1)
                                    ->required()
                                    ->live(),

                                Select::make('cost_method')
                                    ->label('طريقة توزيع التكلفة')
                                    ->options([
                                        'percent' => 'نسبة من التكلفة المتبقية (%)',
                                        'fixed' => 'سعر ثابت للوحدة (ج.م)',
                                    ])
                                    ->default('percent')
                                    ->required()
                                    ->live(),

                                TextInput::make('cost_value')
                                    ->label('القيمة')
                                    ->numeric()
                                    ->step(0.0001)
                                    ->minValue(0)
                                    ->default(0)
                                    ->live()
                                    ->suffix(fn (Get $get): string => $get('cost_method') === 'fixed' ? 'ج.م/وحدة' : '%'),

                                FormPlaceholder::make('expected_unit_cost')
                                    ->hiddenLabel()
                                    ->content(function (Get $get): string {
                                        $preview = app(ManufacturingCostPreviewService::class)->previewRecipe(
                                            $get('../../inputs') ?? [],
                                            $get('../../main_product_id'),
                                            $get('../../batch_quantity'),
                                            $get('../../extra_outputs') ?? [],
                                        );
                                        foreach ($preview['outputs'] as $out) {
                                            if (! $out['is_main'] && (int) $out['product_id'] === (int) $get('product_id')) {
                                                return number_format($out['unit_cost'], 4).' ج.م';
                                            }
                                        }

                                        return '—';
                                    }),
                            ]),
                    ]),

                Section::make('التكلفة المتوقعة للمخرجات')
                    ->schema([
                        FormPlaceholder::make('expected_costs_preview')
                            ->hiddenLabel()
                            ->content(function (Get $get) {
                                $preview = app(ManufacturingCostPreviewService::class)->previewRecipe(
                                    $get('inputs') ?? [],
                                    $get('main_product_id'),
                                    $get('batch_quantity'),
                                    $get('extra_outputs') ?? [],
                                );

                                return app(ManufacturingCostPreviewService::class)->renderRecipePreviewHtml($preview);
                            }),
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

                        TextEntry::make('mainOutput.product.name')
                            ->label('المنتج الرئيسي'),

                        TextEntry::make('mainOutput.expected_quantity')
                            ->label('كمية الدفعة')
                            ->formatStateUsing(fn ($record, $state) => $state.' '.Product::formatUnit($record?->mainOutput?->product?->unit)),

                        TextEntry::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('المدخلات (لدفعة واحدة)')
                    ->schema([
                        RepeatableEntry::make('inputs')
                            ->label('المدخلات')
                            ->table([
                                InfolistTableColumn::make('المنتج')->width('240px'),
                                InfolistTableColumn::make('الوحدة')->width('100px'),
                                InfolistTableColumn::make('الكمية')->width('120px'),
                                InfolistTableColumn::make('تكلفة الوحدة الحالية (ج.م)')->width('160px'),
                                InfolistTableColumn::make('تكلفة السطر (ج.م)')->width('160px'),
                            ])
                            ->schema([
                                TextEntry::make('product.name')->label('المنتج'),
                                TextEntry::make('unit')->label('الوحدة')->state(fn ($record) => Product::formatUnit($record->product?->unit)),
                                TextEntry::make('quantity')->label('الكمية'),
                                TextEntry::make('unit_cost')->label('تكلفة الوحدة الحالية (ج.م)')->state(fn ($record) => (float) ($record->product->cost ?? 0))->numeric(4),
                                TextEntry::make('line_cost')->label('تكلفة السطر (ج.م)')->state(fn ($record) => round((float) $record->quantity * (float) ($record->product->cost ?? 0), 2))->money('EGP'),
                            ]),

                        TextEntry::make('total_batch_cost')
                            ->label('إجمالي تكلفة الدفعة')
                            ->state(function (ManufacturingRecipe $record): float {
                                $inputs = $record->inputs()->with('product')->get();
                                $total = 0.0;
                                foreach ($inputs as $input) {
                                    $total += (float) $input->quantity * (float) ($input->product->cost ?? 0);
                                }

                                return round($total, 2);
                            })
                            ->money('EGP'),
                    ]),

                Section::make('مخرجات إضافية (اختياري)')
                    ->schema([
                        RepeatableEntry::make('extraOutputs')
                            ->label('مخرجات إضافية')
                            ->state(fn (ManufacturingRecipe $record) => $record->outputs()->where('is_main', false)->with('product')->get())
                            ->table([
                                InfolistTableColumn::make('المنتج')->width('220px'),
                                InfolistTableColumn::make('الوحدة')->width('100px'),
                                InfolistTableColumn::make('الكمية المتوقعة')->width('120px'),
                                InfolistTableColumn::make('طريقة توزيع التكلفة')->width('180px'),
                                InfolistTableColumn::make('القيمة')->width('120px'),
                                InfolistTableColumn::make('التكلفة المتوقعة للوحدة')->width('150px'),
                            ])
                            ->schema([
                                TextEntry::make('product.name')->label('المنتج'),
                                TextEntry::make('unit')->label('الوحدة')->state(fn ($record) => Product::formatUnit($record->product?->unit)),
                                TextEntry::make('expected_quantity')->label('الكمية المتوقعة'),
                                TextEntry::make('cost_method')->label('طريقة توزيع التكلفة')
                                    ->formatStateUsing(fn ($state) => $state === 'fixed' ? 'سعر ثابت للوحدة (ج.م)' : 'نسبة من التكلفة المتبقية (%)'),
                                TextEntry::make('cost_value')->label('القيمة')
                                    ->formatStateUsing(fn ($record) => $record->cost_method === 'fixed' ? $record->cost_value.' ج.م/وحدة' : $record->cost_value.' %'),
                                TextEntry::make('expected_unit_cost')->label('التكلفة المتوقعة للوحدة')
                                    ->state(function ($record) {
                                        $recipe = $record->recipe;
                                        if (! $recipe) {
                                            return '-';
                                        }
                                        $inputs = $recipe->inputs->map(fn ($in) => ['product_id' => $in->product_id, 'quantity' => (float) $in->quantity])->all();
                                        $main = $recipe->mainOutput;
                                        $extras = $recipe->outputs->where('is_main', false)->map(fn ($out) => [
                                            'product_id' => $out->product_id,
                                            'expected_quantity' => (float) $out->expected_quantity,
                                            'cost_method' => $out->cost_method,
                                            'cost_value' => (float) $out->cost_value,
                                        ])->all();
                                        $preview = app(ManufacturingCostPreviewService::class)->previewRecipe(
                                            $inputs,
                                            $main?->product_id,
                                            $main?->expected_quantity,
                                            $extras,
                                        );
                                        foreach ($preview['outputs'] as $out) {
                                            if (! $out['is_main'] && $out['product_id'] == $record->product_id) {
                                                return number_format($out['unit_cost'], 4).' ج.م';
                                            }
                                        }

                                        return '-';
                                    }),
                            ]),
                    ]),

                Section::make('التكلفة المتوقعة للمخرجات')
                    ->schema([
                        TextEntry::make('expected_costs_preview')
                            ->hiddenLabel()
                            ->html()->state(function (ManufacturingRecipe $record) {
                                $inputs = $record->inputs->map(fn ($in) => ['product_id' => $in->product_id, 'quantity' => (float) $in->quantity])->all();
                                $main = $record->mainOutput;
                                $extras = $record->outputs->where('is_main', false)->map(fn ($out) => [
                                    'product_id' => $out->product_id,
                                    'expected_quantity' => (float) $out->expected_quantity,
                                    'cost_method' => $out->cost_method,
                                    'cost_value' => (float) $out->cost_value,
                                ])->all();
                                $preview = app(ManufacturingCostPreviewService::class)->previewRecipe(
                                    $inputs,
                                    $main?->product_id,
                                    $main?->expected_quantity,
                                    $extras,
                                );

                                return app(ManufacturingCostPreviewService::class)->renderRecipePreviewHtml($preview);
                            }),
                    ]),
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

                TextColumn::make('main_product_batch')
                    ->label('المنتج الرئيسي (كمية الدفعة)')
                    ->state(function (ManufacturingRecipe $record): string {
                        $main = $record->mainOutput;
                        if (! $main || ! $main->product) {
                            return '-';
                        }
                        $qty = (float) $main->expected_quantity;
                        $unit = Product::formatUnit($main->product->unit);

                        return "{$main->product->name} ({$qty} {$unit})";
                    }),

                TextColumn::make('current_batch_cost')
                    ->label('تكلفة الدفعة الحالية')
                    ->state(function (ManufacturingRecipe $record): float {
                        $inputs = $record->relationLoaded('inputs') ? $record->inputs : $record->inputs()->with('product')->get();
                        $total = 0.0;
                        foreach ($inputs as $input) {
                            $cost = (float) ($input->product->cost ?? 0);
                            $total += (float) $input->quantity * $cost;
                        }

                        return round($total, 2);
                    })
                    ->money('EGP'),

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
