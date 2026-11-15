<?php

namespace App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders;

use App\Enums\ProductType;
use App\Modules\Manufacturing\Filament\Actions\CompleteManufacturingOrderAction;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\CreateManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\EditManufacturingOrder;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\ListManufacturingOrders;
use App\Modules\Manufacturing\Filament\Resources\ManufacturingOrders\Pages\ViewManufacturingOrder;
use App\Filament\Traits\BelongsToModule;
use App\Models\ManufacturingOrder;
use App\Models\ManufacturingRecipe;
use App\Models\Product;
use App\Models\Warehouse;
use App\Modules\Manufacturing\Services\ManufacturingCostPreviewService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder as FormPlaceholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn as FormTableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ManufacturingOrderResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = ManufacturingOrder::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'أوامر التصنيع';

    protected static ?string $modelLabel = 'أمر تصنيع';

    protected static ?string $pluralModelLabel = 'أوامر التصنيع';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof ManufacturingOrder && $record->closed_at === null && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof ManufacturingOrder && $record->closed_at === null && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        $stockProductOptions = fn (): array => Product::query()
            ->whereIn('type', [
                ProductType::RawMaterial->value,
                ProductType::Consumable->value,
            ])
            ->pluck('name', 'id')
            ->all();

        return $schema->components([
            Section::make('بيانات أمر التصنيع')->schema([
                Select::make('manufacturing_recipe_id')
                    ->label('وصفة التصنيع')
                    ->options(fn () => ManufacturingRecipe::active()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->disabledOn('edit')
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        if (! $state) {
                            return;
                        }
                        $recipe = ManufacturingRecipe::with(['mainOutput.product', 'inputs.product', 'outputs.product'])->find($state);
                        if (! $recipe || ! $recipe->mainOutput) {
                            return;
                        }

                        $batchQty = (float) $recipe->mainOutput->expected_quantity;
                        $plannedQty = (float) ($get('planned_main_quantity') ?? 0);
                        if ($plannedQty <= 0) {
                            $plannedQty = $batchQty;
                            $set('planned_main_quantity', $plannedQty);
                        }

                        $scale = $batchQty > 0 ? $plannedQty / $batchQty : 1.0;

                        $inputs = [];
                        foreach ($recipe->inputs as $input) {
                            $qty = round((float) $input->quantity * $scale, 3);
                            $cost = (float) ($input->product->cost ?? 0);
                            $inputs[] = [
                                'product_id' => $input->product_id,
                                'unit' => Product::formatUnit($input->product?->unit),
                                'planned_quantity' => $qty,
                                'actual_quantity' => $qty,
                                'unit_cost' => $cost,
                                'line_cost' => round($qty * $cost, 2),
                            ];
                        }
                        $set('inputs', $inputs);

                        $outputs = [];
                        foreach ($recipe->outputs as $output) {
                            $qty = round((float) $output->expected_quantity * $scale, 3);
                            $outputs[] = [
                                'product_id' => $output->product_id,
                                'unit' => Product::formatUnit($output->product?->unit),
                                'is_main' => (bool) $output->is_main,
                                'expected_quantity' => $qty,
                                'actual_quantity' => $qty,
                                'cost_method' => $output->cost_method ?? 'percent',
                                'cost_value' => (float) ($output->cost_value ?? 0),
                                'variance_quantity' => 0.0,
                            ];
                        }
                        $set('outputs', $outputs);
                    }),

                Select::make('source_warehouse_id')
                    ->label('مخزن المصدر')
                    ->options(fn () => Warehouse::active()->pluck('name', 'id'))
                    ->default(fn () => Warehouse::default()->id)
                    ->required()
                    ->disabledOn('edit'),

                Select::make('destination_warehouse_id')
                    ->label('مخزن الوجهة')
                    ->options(fn () => Warehouse::active()->pluck('name', 'id'))
                    ->default(fn () => Warehouse::default()->id)
                    ->required()
                    ->disabledOn('edit'),

                TextInput::make('planned_main_quantity')
                    ->label(function (Get $get, ?ManufacturingOrder $record): string {
                        $recipeId = $get('manufacturing_recipe_id') ?? $record?->manufacturing_recipe_id;
                        if ($recipeId) {
                            $recipe = ManufacturingRecipe::with('mainOutput.product')->find($recipeId);
                            $main = $recipe?->mainOutput;
                            if ($main && $main->product) {
                                $unit = Product::formatUnit($main->product->unit);

                                return "الكمية المطلوب إنتاجها من {$main->product->name} ({$unit})";
                            }
                        }

                        return 'الكمية المطلوب إنتاجها من المنتج الرئيسي';
                    })
                    ->helperText(function (Get $get, ?ManufacturingOrder $record): ?string {
                        $recipeId = $get('manufacturing_recipe_id') ?? $record?->manufacturing_recipe_id;
                        if ($recipeId) {
                            $recipe = ManufacturingRecipe::with('mainOutput.product')->find($recipeId);
                            $batchQty = (float) ($recipe?->mainOutput->expected_quantity ?? 0);
                            if ($batchQty > 0) {
                                $qty = (float) ($get('planned_main_quantity') ?? 0);
                                $unit = $recipe->mainOutput->product ? Product::formatUnit($recipe->mainOutput->product->unit) : '';
                                $batches = round($qty / $batchQty, 3);

                                return "= {$batches} دفعة (الدفعة {$batchQty} {$unit})";
                            }
                        }

                        return null;
                    })
                    ->numeric()
                    ->step(0.001)
                    ->minValue(0.001)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, Get $get, ?ManufacturingOrder $record) {
                        $recipeId = $get('manufacturing_recipe_id') ?? $record?->manufacturing_recipe_id;
                        if (! $recipeId) {
                            return;
                        }

                        $recipe = ManufacturingRecipe::with(['mainOutput', 'inputs', 'outputs'])->find($recipeId);
                        if (! $recipe || ! $recipe->mainOutput) {
                            return;
                        }

                        $batchQty = (float) $recipe->mainOutput->expected_quantity;
                        $newPlanned = (float) ($state ?? 0);
                        if ($newPlanned <= 0 || $batchQty <= 0) {
                            return;
                        }

                        $newScale = $newPlanned / $batchQty;

                        // Re-scale inputs
                        $currentInputs = $get('inputs') ?? [];
                        $recipeInputsByProduct = $recipe->inputs->keyBy('product_id');
                        $updatedInputs = [];
                        foreach ($currentInputs as $row) {
                            $pid = $row['product_id'] ?? null;
                            $recipeInput = $pid ? $recipeInputsByProduct->get($pid) : null;
                            if ($recipeInput) {
                                $oldPlanned = (float) ($row['planned_quantity'] ?? 0);
                                $oldActual = (float) ($row['actual_quantity'] ?? 0);
                                $newRowPlanned = round((float) $recipeInput->quantity * $newScale, 3);
                                $row['planned_quantity'] = $newRowPlanned;
                                if (abs($oldActual - $oldPlanned) < 0.0001) {
                                    $row['actual_quantity'] = $newRowPlanned;
                                }
                                $cost = (float) ($row['unit_cost'] ?? 0);
                                $row['line_cost'] = round((float) $row['actual_quantity'] * $cost, 2);
                            }
                            $updatedInputs[] = $row;
                        }
                        $set('inputs', $updatedInputs);

                        // Re-scale outputs
                        $currentOutputs = $get('outputs') ?? [];
                        $recipeOutputsByProduct = $recipe->outputs->keyBy('product_id');
                        $updatedOutputs = [];
                        foreach ($currentOutputs as $row) {
                            $pid = $row['product_id'] ?? null;
                            $isMain = (bool) ($row['is_main'] ?? false);
                            if ($isMain) {
                                $oldExpected = (float) ($row['expected_quantity'] ?? 0);
                                $oldActual = (float) ($row['actual_quantity'] ?? 0);
                                $row['expected_quantity'] = $newPlanned;
                                if (abs($oldActual - $oldExpected) < 0.0001) {
                                    $row['actual_quantity'] = $newPlanned;
                                }
                                $row['variance_quantity'] = round((float) $row['actual_quantity'] - $newPlanned, 3);
                            } else {
                                $recipeOutput = $pid ? $recipeOutputsByProduct->get($pid) : null;
                                if ($recipeOutput) {
                                    $oldExpected = (float) ($row['expected_quantity'] ?? 0);
                                    $oldActual = (float) ($row['actual_quantity'] ?? 0);
                                    $newRowExpected = round((float) $recipeOutput->expected_quantity * $newScale, 3);
                                    $row['expected_quantity'] = $newRowExpected;
                                    if (abs($oldActual - $oldExpected) < 0.0001) {
                                        $row['actual_quantity'] = $newRowExpected;
                                    }
                                    $row['variance_quantity'] = round((float) $row['actual_quantity'] - $newRowExpected, 3);
                                }
                            }
                            $updatedOutputs[] = $row;
                        }
                        $set('outputs', $updatedOutputs);
                    }),

                Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
            ])->columns(2),

            Section::make('المدخلات')->schema([
                Repeater::make('inputs')
                    ->label('المدخلات')
                    ->minItems(1)
                    ->table([
                        FormTableColumn::make('المنتج')->width('220px'),
                        FormTableColumn::make('الوحدة')->width('100px'),
                        FormTableColumn::make('الكمية المخططة')->width('120px'),
                        FormTableColumn::make('الكمية الفعلية')->width('120px'),
                        FormTableColumn::make('تكلفة الوحدة (ج.م)')->width('140px'),
                        FormTableColumn::make('تكلفة السطر (ج.م)')->width('140px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
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
                                $actual = (float) ($get('actual_quantity') ?? 0);
                                $set('line_cost', round($actual * $cost, 2));
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

                        TextInput::make('planned_quantity')
                            ->label('الكمية المخططة')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        TextInput::make('actual_quantity')
                            ->label('الكمية الفعلية')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(0)
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
                            ->label('تكلفة الوحدة (ج.م)')
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
                                $cost = (float) ($get('unit_cost') ?? ($record ? (float) $record->product?->cost : 0));
                                $actual = (float) ($get('actual_quantity') ?? ($record ? (float) $record->actual_quantity : 0));

                                return round($actual * $cost, 2);
                            }),
                    ]),
            ]),

            Section::make('المخرجات')->schema([
                Repeater::make('outputs')
                    ->label('المخرجات')
                    ->minItems(1)
                    ->table([
                        FormTableColumn::make('المنتج')->width('200px'),
                        FormTableColumn::make('الوحدة')->width('90px'),
                        FormTableColumn::make('النوع')->width('90px'),
                        FormTableColumn::make('الكمية المخططة')->width('110px'),
                        FormTableColumn::make('الكمية الفعلية')->width('110px'),
                        FormTableColumn::make('طريقة توزيع التكلفة')->width('220px'),
                        FormTableColumn::make('القيمة')->width('110px'),
                        FormTableColumn::make('الانحراف')->width('100px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('is_main')->default(false),

                        Select::make('product_id')
                            ->label('المنتج')
                            ->options($stockProductOptions)
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get) => (bool) $get('is_main'))
                            ->dehydrated()
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

                        FormPlaceholder::make('type_label')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => $get('is_main') ? 'رئيسي' : 'إضافي'),
                        TextInput::make('expected_quantity')
                            ->label('الكمية المخططة')
                            ->disabled()
                            ->dehydrated()
                            ->default(0),

                        TextInput::make('actual_quantity')
                            ->label('الكمية الفعلية')
                            ->numeric()
                            ->step(0.001)
                            ->minValue(fn (Get $get) => $get('is_main') ? 0.001 : 0)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $planned = (float) ($get('expected_quantity') ?? 0);
                                $actual = (float) ($state ?? 0);
                                $set('variance_quantity', round($actual - $planned, 3));
                            }),

                        Select::make('cost_method')
                            ->label('طريقة توزيع التكلفة')
                            ->options(fn (Get $get): array => $get('is_main')
                                ? ['percent' => 'يأخذ الباقي']
                                : ['fixed' => 'سعر ثابت للوحدة (ج.م)', 'percent' => 'نسبة من التكلفة المتبقية (%)'])
                            ->disabled(fn (Get $get) => (bool) $get('is_main'))
                            ->dehydrated()
                            ->selectablePlaceholder(false)
                            ->default('percent')
                            ->live(),

                        TextInput::make('cost_value')
                            ->label('القيمة')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->default(0)
                            ->disabled(fn (Get $get) => (bool) $get('is_main'))
                            ->dehydrated()
                            ->live()
                            ->suffix(fn (Get $get): ?string => $get('is_main') ? null : ($get('cost_method') === 'fixed' ? 'ج.م/وحدة' : '%')),

                        FormPlaceholder::make('variance_quantity')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => (string) round((float) ($get('actual_quantity') ?? 0) - (float) ($get('expected_quantity') ?? 0), 3)),
                    ])
                    ->deleteAction(function (Action $action) {
                        $action->visible(fn (array $arguments, Repeater $component): bool => empty(($component->getRawState() ?? [])[$arguments['item'] ?? '']['is_main'] ?? false));
                        $action->action(function (array $arguments, Repeater $component): void {
                            $items = $component->getRawState() ?? [];
                            $itemKey = $arguments['item'] ?? null;
                            if ($itemKey && ! empty($items[$itemKey]['is_main'])) {
                                Notification::make()->title('لا يمكن حذف المخرج الرئيسي')->danger()->send();

                                return;
                            }
                            unset($items[$itemKey]);
                            $component->rawState($items);
                            $component->callAfterStateUpdated();
                        });
                    }),
            ]),

            Section::make('ملخص التكاليف التقديرية')->schema([
                FormPlaceholder::make('summary_preview')
                    ->hiddenLabel()
                    ->content(function (Get $get) {
                        $preview = app(ManufacturingCostPreviewService::class)->previewOrder(
                            $get('inputs') ?? [],
                            $get('outputs') ?? [],
                        );

                        return app(ManufacturingCostPreviewService::class)->renderOrderSummaryHtml($preview);
                    }),
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
                TextEntry::make('user.name')->label('أنشأه'),
                TextEntry::make('closedBy.name')->label('أكمله'),
                TextEntry::make('closed_at')->label('تاريخ الإكمال')->dateTime()->placeholder('مسودة'),
                TextEntry::make('business_date')->label('يوم العمل')->date(),
                TextEntry::make('notes')->label('ملاحظات')->columnSpanFull(),
            ])->columns(3),

            Section::make('ملخص التكلفة والإنتاج')->schema([
                TextEntry::make('total_input_cost')
                    ->label('إجمالي تكلفة المدخلات')
                    ->money('EGP'),

                TextEntry::make('main_unit_cost')
                    ->label('تكلفة الوحدة من المنتج الرئيسي')
                    ->state(fn (ManufacturingOrder $record): string => $record->mainOutput?->unit_cost !== null
                        ? number_format((float) $record->mainOutput->unit_cost, 4).' ج.م'
                        : '-'),

                TextEntry::make('yield_percentage')
                    ->label('نسبة الإنتاج (الفعلي ÷ المخطط)')
                    ->state(function (ManufacturingOrder $record): string {
                        $main = $record->mainOutput;
                        if (! $main || (float) $main->expected_quantity <= 0) {
                            return '-';
                        }
                        $actual = (float) $main->actual_quantity;
                        $planned = (float) $main->expected_quantity;
                        $pct = round(($actual / $planned) * 100, 1);

                        return $pct.'% ('.$actual.' / '.$planned.')';
                    }),
            ])->columns(3),

            Section::make('المدخلات')->schema([
                RepeatableEntry::make('inputs')
                    ->label('المدخلات')
                    ->table([
                        InfolistTableColumn::make('المنتج')->width('220px'),
                        InfolistTableColumn::make('الوحدة')->width('100px'),
                        InfolistTableColumn::make('الكمية المخططة')->width('120px'),
                        InfolistTableColumn::make('الكمية الفعلية')->width('120px'),
                        InfolistTableColumn::make('تكلفة الوحدة (ج.م)')->width('140px'),
                        InfolistTableColumn::make('التكلفة الإجمالية (ج.م)')->width('140px'),
                    ])
                    ->schema([
                        TextEntry::make('product.name')->label('المنتج'),
                        TextEntry::make('unit')->label('الوحدة')->state(fn ($record) => Product::formatUnit($record->product?->unit)),
                        TextEntry::make('planned_quantity')->label('المخطط'),
                        TextEntry::make('actual_quantity')->label('الفعلي'),
                        TextEntry::make('unit_cost')->label('تكلفة الوحدة (ج.م)')->numeric(4),
                        TextEntry::make('total_cost')->label('التكلفة الإجمالية')->money('EGP'),
                    ]),
            ]),

            Section::make('المخرجات والانحراف')->schema([
                RepeatableEntry::make('outputs')
                    ->label('المخرجات')
                    ->table([
                        InfolistTableColumn::make('المنتج')->width('200px'),
                        InfolistTableColumn::make('الوحدة')->width('90px'),
                        InfolistTableColumn::make('النوع')->width('90px'),
                        InfolistTableColumn::make('الكمية المخططة')->width('110px'),
                        InfolistTableColumn::make('الكمية الفعلية')->width('110px'),
                        InfolistTableColumn::make('التكلفة المخصصة (ج.م)')->width('130px'),
                        InfolistTableColumn::make('تكلفة الوحدة (ج.م)')->width('130px'),
                        InfolistTableColumn::make('انحراف الكمية')->width('110px'),
                        InfolistTableColumn::make('قيمة الانحراف (ج.م)')->width('130px'),
                    ])
                    ->schema([
                        TextEntry::make('product.name')->label('المنتج'),
                        TextEntry::make('unit')->label('الوحدة')->state(fn ($record) => Product::formatUnit($record->product?->unit)),
                        TextEntry::make('is_main')->label('النوع')->formatStateUsing(fn ($state) => $state ? 'رئيسي' : 'إضافي'),
                        TextEntry::make('expected_quantity')->label('المتوقع'),
                        TextEntry::make('actual_quantity')->label('الفعلي'),
                        TextEntry::make('allocated_cost')->label('التكلفة المخصصة')->money('EGP'),
                        TextEntry::make('unit_cost')->label('تكلفة الوحدة (ج.م)')->numeric(4),
                        TextEntry::make('variance_quantity')->label('انحراف الكمية'),
                        TextEntry::make('variance_value')->label('قيمة الانحراف')->money('EGP'),
                    ]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم الأمر')->sortable(),
            TextColumn::make('recipe.name')->label('الوصفة')->searchable()->sortable(),
            TextColumn::make('main_product_planned')
                ->label('المنتج الرئيسي (الكمية المطلوبة)')
                ->state(function (ManufacturingOrder $record): string {
                    $main = $record->mainOutput;
                    $pName = $main?->product->name ?? '-';
                    $qty = (float) $record->planned_main_quantity;
                    $unit = $main?->product ? Product::formatUnit($main->product->unit) : '';

                    return "{$pName} ({$qty} {$unit})";
                }),
            TextColumn::make('warehouse_route')
                ->label('المسار')
                ->state(fn (ManufacturingOrder $record): string => ($record->sourceWarehouse->name ?? '-').' ← '.($record->destinationWarehouse->name ?? '-')),
            TextColumn::make('total_input_cost')->label('تكلفة المدخلات')->money('EGP')->sortable(),
            TextColumn::make('status')
                ->label('الحالة')
                ->state(fn (ManufacturingOrder $record): string => $record->closed_at !== null ? 'مكتمل' : 'مسودة')
                ->badge()
                ->color(fn (string $state): string => $state === 'مكتمل' ? 'success' : 'warning'),
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
