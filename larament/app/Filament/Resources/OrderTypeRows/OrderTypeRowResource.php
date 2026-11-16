<?php

namespace App\Filament\Resources\OrderTypeRows;

use App\Enums\GuestsMode;
use App\Enums\OrderBehavior;
use App\Enums\OrderTypeIcon;
use App\Filament\Resources\OrderTypeRows\Pages\CreateOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\EditOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\ListOrderTypeRows;
use App\Models\OrderTypeRow;
use App\Orders\OrderBehaviorRegistry;
use App\Services\FeatureService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderTypeRowResource extends Resource
{
    protected static ?string $model = OrderTypeRow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'نوع طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع الطلبات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('الكود')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    ->helperText('حروف إنجليزية صغيرة وأرقام وشرطات سفلية، ويبدأ بحرف.'),

                TextInput::make('label')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),

                Grid::make(2)
                    ->schema([
                        Select::make('color')
                            ->label('اللون')
                            ->options([
                                'primary' => 'أساسي',
                                'success' => 'نجاح',
                                'info' => 'معلومات',
                                'warning' => 'تحذير',
                                'danger' => 'خطر',
                                'gray' => 'رمادي',
                            ])
                            ->required()
                            ->default('primary'),

                        Select::make('icon')
                            ->label('الأيقونة')
                            ->options(fn (?OrderTypeRow $record): array => OrderTypeIcon::options($record?->icon))
                            ->in(fn (?OrderTypeRow $record): array => OrderTypeIcon::allowedValues($record?->icon))
                            ->searchable()
                            ->allowHtml()
                            ->required()
                            ->default(OrderTypeIcon::Tag->value),
                    ]),

                Grid::make(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),

                        Select::make('behavior')
                            ->label('السلوك')
                            ->options(fn (?OrderTypeRow $record): array => self::behaviorOptions($record))
                            ->live()
                            ->required()
                            ->disabled(fn (?OrderTypeRow $record): bool => (bool) $record?->is_system),
                    ]),

                Section::make('الدفع الآجل')
                    ->schema([
                        Toggle::make('allow_partial_payment')
                            ->label('السماح بالدفع الجزئي')
                            ->helperText('يسمح بإنهاء الطلب مع دفع جزء من المبلغ وتسجيل المتبقي كآجل.')
                            ->default(false),

                        Toggle::make('allow_pay_later')
                            ->label('السماح بالإغلاق والدفع لاحقاً')
                            ->helperText('يسمح بإنهاء الطلب دون دفع أي مبلغ وتسجيل كامل القيمة كآجل.')
                            ->default(false),

                        Toggle::make('require_customer_for_credit')
                            ->label('يتطلب ربط عميل')
                            ->helperText('يشترط تحديد عميل للطلب قبل السماح بالدفع الآجل أو الجزئي.')
                            ->default(true),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('عدد الأفراد')
                    ->schema([
                        Select::make('guests_mode')
                            ->label('عدد الأفراد')
                            ->options(GuestsMode::options())
                            ->default(GuestsMode::OFF->value)
                            ->required()
                            ->helperText('يسأل الكاشير عن عدد الأفراد عند فتح الطلب، والإجباري يمنع إنهاء الطلب بدونه. التقارير تعرض متوسط الفرد.'),
                    ])
                    ->visible(fn (Get $get): bool => OrderTypeRow::supportsGuests($get('behavior')))
                    ->columnSpanFull(),

                Section::make('المعدات')
                    ->description('المعدات التي يمكن للكاشير إضافتها لطلبات هذا النوع. لو لم تختر شيئاً لا يظهر زر «إضافة معدات».')
                    ->schema([
                        CheckboxList::make('equipment')
                            ->hiddenLabel()
                            ->relationship('equipment', 'name', fn (Builder $query) => $query->orderBy('sort_order')->orderBy('name'))
                            ->columns(3),
                    ])
                    ->visible(fn (?OrderTypeRow $record): bool => app(FeatureService::class)->equipmentEnabled()
                        && $record?->behavior !== OrderBehavior::INSTANT)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Order types whose behaviour belongs to a switched-off module (dine-in
     * without Restaurant, delivery without Delivery, web types without Web
     * orders) are not listed and cannot be opened.
     */
    public static function getEloquentQuery(): Builder
    {
        $hidden = app(OrderBehaviorRegistry::class)->unavailableCodes();

        return parent::getEloquentQuery()->when($hidden !== [], fn (Builder $query) => $query->whereNotIn('behavior', $hidden));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('label')
                    ->label('الاسم')
                    ->badge()
                    ->color(fn (OrderTypeRow $record): string => $record->color)
                    ->icon(fn (OrderTypeRow $record): string => OrderTypeIcon::safeIcon($record->icon))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('behavior')
                    ->label('السلوك')
                    ->formatStateUsing(fn (OrderBehavior|string|null $state): string => self::behaviorLabel($state))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                IconColumn::make('is_system')
                    ->label('نظامي')
                    ->boolean(),

                IconColumn::make('allow_partial_payment')
                    ->label('السماح بالدفع الجزئي')
                    ->boolean(),

                IconColumn::make('allow_pay_later')
                    ->label('السماح بالإغلاق والدفع لاحقاً')
                    ->boolean(),

                IconColumn::make('require_customer_for_credit')
                    ->label('يتطلب ربط عميل')
                    ->boolean(),

                TextColumn::make('guests_mode')
                    ->label('عدد الأفراد')
                    ->formatStateUsing(fn (GuestsMode|string|null $state): string => ($state instanceof GuestsMode ? $state : (GuestsMode::tryFrom((string) $state) ?? GuestsMode::OFF))->label())
                    ->toggleable(),
            ])
            ->defaultSort('id')
            ->recordActions([
                Action::make('floor')->label('تصميم الشبكة')->url(fn (OrderTypeRow $record): string => route('floor.designer', $record->code))->visible(fn (OrderTypeRow $record): bool => $record->behavior === OrderBehavior::DINE_IN),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (OrderTypeRow $record): bool => ! $record->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderTypeRows::route('/'),
            'create' => CreateOrderTypeRow::route('/create'),
            'edit' => EditOrderTypeRow::route('/{record}/edit'),
        ];
    }

    /**
     * Behaviours an admin may pick for an order type. When editing, the row's
     * own behaviour is always offered even if it is not selectable for new
     * types (instant sale, web orders), so the select shows it and the form
     * validates.
     *
     * @return array<string, string>
     */
    public static function behaviorOptions(?OrderTypeRow $record = null): array
    {
        $registry = app(OrderBehaviorRegistry::class);
        $options = $registry->selectableLabels();

        $current = $record?->behavior;
        $code = $current instanceof OrderBehavior ? $current->value : $current;

        if (is_string($code) && $code !== '' && ! array_key_exists($code, $options)) {
            $options[$code] = $registry->label($code);
        }

        return $options;
    }

    public static function behaviorLabel(OrderBehavior|string|null $state): string
    {
        return app(OrderBehaviorRegistry::class)->label($state);
    }
}
