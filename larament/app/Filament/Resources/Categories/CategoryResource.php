<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Pages\ViewCategory;
use App\Models\Category;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'فئة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الفئات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم الفئة')
                    ->required()
                    ->maxLength(255),

                Select::make('parent_id')
                    ->label('التصنيف الرئيسي')
                    ->placeholder('تصنيف رئيسي')
                    ->options(fn (?Category $record): array => Category::query()
                        ->whereNull('parent_id')
                        ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (filled($state)) {
                            $set('warehouse_id', null);
                        }
                    })
                    ->disabled(fn (?Category $record): bool => $record !== null && $record->children()->exists())
                    ->helperText('اتركه فارغاً ليبقى تصنيفاً رئيسياً. التصنيف الفرعي لا يمكن أن يكون له تصنيف فرعي.')
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value === null || $value === '') {
                            return;
                        }

                        $parent = Category::query()->find($value);
                        if (! $parent instanceof Category || $parent->parent_id !== null) {
                            $fail('لا يمكن إنشاء أكثر من مستويين للتصنيفات.');
                        }
                    }),

                Select::make('warehouse_id')
                    ->label('مخزن السحب')
                    ->relationship(
                        'warehouse',
                        'name',
                        fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('المخزن الافتراضي')
                    ->helperText('يُستخدم لسحب مخزون أصناف هذا التصنيف الرئيسي. التصنيف الفرعي يتبع تصنيفَه الرئيسي.')
                    ->visible(fn (Get $get): bool => blank($get('parent_id')))
                    ->dehydrated(fn (Get $get): bool => blank($get('parent_id'))),

                Select::make('blockedOrderTypes')
                    ->label('أنواع الطلبات المحظورة')
                    ->relationship('blockedOrderTypes', 'label')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('قائمة حظر. حظر التصنيف الرئيسي يسري على تصنيفاته الفرعية، والفرعي يمكنه إضافة حظر خاص به.'),

                Section::make('شاشة المطبخ')
                    ->description('توجيه أصناف هذه الفئة داخل شاشة المطبخ')
                    ->icon('heroicon-m-tv')
                    ->columns(3)
                    ->schema([
                        Select::make('kitchen_station_id')
                            ->label('محطة المطبخ')
                            ->relationship(
                                'kitchenStation',
                                'name',
                                fn (Builder $query): Builder => $query->where('is_active', true)
                            )
                            ->placeholder('بدون محطة (عام)')
                            ->searchable()
                            ->nullable(),

                        Toggle::make('show_on_kds')
                            ->label('يظهر في شاشة المطبخ')
                            ->default(true)
                            ->inline(false),

                        TextInput::make('prep_minutes')
                            ->label('وقت التحضير')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('دقيقة')
                            ->helperText('يترك فارغاً لاستخدام الوقت الافتراضي')
                            ->nullable(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الفئة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('التصنيف الرئيسي')
                    ->placeholder('رئيسي'),
                TextColumn::make('blockedOrderTypes.label')
                    ->label('أنواع محظورة')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('kitchenStation.name')
                    ->label('محطة المطبخ')
                    ->searchable()
                    ->placeholder('عام'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['parent', 'blockedOrderTypes']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
