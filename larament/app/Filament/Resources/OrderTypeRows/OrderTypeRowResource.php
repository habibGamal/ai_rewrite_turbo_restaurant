<?php

namespace App\Filament\Resources\OrderTypeRows;

use App\Enums\OrderBehavior;
use App\Filament\Resources\OrderTypeRows\Pages\CreateOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\EditOrderTypeRow;
use App\Filament\Resources\OrderTypeRows\Pages\ListOrderTypeRows;
use App\Models\OrderTypeRow;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderTypeRowResource extends Resource
{
    protected static ?string $model = OrderTypeRow::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المطعم';

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

                        TextInput::make('icon')
                            ->label('الأيقونة')
                            ->required()
                            ->default('heroicon-o-tag')
                            ->maxLength(255),
                    ]),

                Grid::make(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),

                        Select::make('behavior')
                            ->label('السلوك')
                            ->options(self::behaviorOptions())
                            ->required()
                            ->disabled(fn (?OrderTypeRow $record): bool => (bool) $record?->is_system),
                    ]),
            ]);
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
                    ->icon(fn (OrderTypeRow $record): string => $record->icon)
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
            ])
            ->defaultSort('id')
            ->recordActions([
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
     * @return array<string, string>
     */
    public static function behaviorOptions(): array
    {
        return [
            OrderBehavior::DINE_IN->value => 'صالة',
            OrderBehavior::DELIVERY->value => 'دليفري',
            OrderBehavior::COUNTER->value => 'كاونتر',
            OrderBehavior::COMPANIES->value => 'شركات',
        ];
    }

    public static function behaviorLabel(OrderBehavior|string|null $state): string
    {
        $behavior = $state instanceof OrderBehavior ? $state : OrderBehavior::tryFrom((string) $state);

        if ($behavior === null) {
            return '';
        }

        $labels = self::behaviorOptions() + [
            OrderBehavior::WEB_DELIVERY->value => 'اونلاين دليفري',
            OrderBehavior::WEB_TAKEAWAY->value => 'اونلاين تيك أواي',
        ];

        return $labels[$behavior->value] ?? $behavior->value;
    }
}
