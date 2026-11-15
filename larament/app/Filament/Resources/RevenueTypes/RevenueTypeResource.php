<?php

namespace App\Filament\Resources\RevenueTypes;

use App\Filament\Resources\RevenueTypes\Pages\CreateRevenueType;
use App\Filament\Resources\RevenueTypes\Pages\EditRevenueType;
use App\Filament\Resources\RevenueTypes\Pages\ListRevenueTypes;
use App\Filament\Traits\BelongsToModule;
use App\Models\RevenueType;
use App\Modules\ModuleScreens;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Categories of misc shift revenues («بنود الإيرادات»). Revenue rows
 * themselves are entered only on the shift money page, never here.
 * Hidden (404) while the REVENUES feature is off, see ModuleScreens.
 */
class RevenueTypeResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = RevenueType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المصروفات';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return ModuleScreens::allows(static::class) && parent::shouldRegisterNavigation();
    }

    public static function getModelLabel(): string
    {
        return 'بند إيراد';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بنود الإيرادات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم بند الإيراد')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('sort_order')
                    ->label('الترتيب')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('نشط')
                    ->helperText('البند غير النشط لا يظهر عند تسجيل إيراد جديد، ويبقى في التقارير القديمة.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('اسم بند الإيراد')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('revenues_count')
                    ->label('عدد الإيرادات')
                    ->counts('revenues')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('الترتيب')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('نشط'),
            ])
            ->recordActions([
                EditAction::make(),
                // A type that already has revenues stays for history; deactivate it instead.
                DeleteAction::make()
                    ->hidden(fn (RevenueType $record): bool => $record->revenues()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevenueTypes::route('/'),
            'create' => CreateRevenueType::route('/create'),
            'edit' => EditRevenueType::route('/{record}/edit'),
        ];
    }
}
