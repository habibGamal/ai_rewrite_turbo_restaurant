<?php

namespace App\Filament\Resources\ProductGroups;

use App\Filament\Resources\ProductGroups\Pages\CreateProductGroup;
use App\Filament\Resources\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroups\Pages\ProductGroupStock;
use App\Filament\Resources\ProductGroups\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\FeatureService;
use App\Services\Products\ProductVariantService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Retail product groups (الموديلات): one style whose sizes/colours are
 * generated as consumable products (SKUs). Behind the vendor-only VARIANTS flag.
 */
class ProductGroupResource extends Resource
{
    protected static ?string $model = ProductGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return app(FeatureService::class)->variantsEnabled() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return app(FeatureService::class)->variantsEnabled() && parent::canAccess();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->variantsEnabled() && parent::getViewAnyAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->variantsEnabled() && parent::getCreateAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return (app(FeatureService::class)->variantsEnabled() && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return (app(FeatureService::class)->variantsEnabled() && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->variantsEnabled() && parent::getDeleteAnyAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function canGloballySearch(): bool
    {
        return app(FeatureService::class)->variantsEnabled() && parent::canGloballySearch();
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        if (! app(FeatureService::class)->variantsEnabled()) {
            return collect();
        }

        return parent::getGlobalSearchResults($search);
    }

    public static function getModelLabel(): string
    {
        return 'موديل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الموديلات';
    }

    /**
     * Units a group may use: every product unit except the weighed (scale) ones.
     *
     * @return array<string, string>
     */
    public static function unitOptions(): array
    {
        return array_diff_key(Product::getUnitOptions(), array_flip(ProductVariantService::WEIGHT_UNITS));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الموديل')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الموديل')
                            ->required()
                            ->maxLength(255),
                        Select::make('category_id')
                            ->label('الفئة')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('brand')
                            ->label('الماركة')
                            ->maxLength(255),
                        TextInput::make('sku_prefix')
                            ->label('بادئة كود الصنف (SKU)')
                            ->maxLength(32)
                            ->helperText('مثال: TSH ينتج أكواداً مثل TSH-BLK-M. اتركه فارغاً لعدم توليد أكواد.'),
                        TextInput::make('base_price')
                            ->label('سعر البيع')
                            ->helperText('يطبق على كل المقاسات/الألوان إلا التي لها سعر خاص.')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('ج.م'),
                        TextInput::make('base_cost')
                            ->label('التكلفة المبدئية')
                            ->helperText('تكلفة المتغيرات الجديدة عند التوليد؛ تتحدث بعدها من فواتير الشراء.')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('ج.م'),
                        Select::make('unit')
                            ->label('الوحدة')
                            ->options(static::unitOptions())
                            ->in(array_keys(static::unitOptions()))
                            ->default('piece')
                            ->required()
                            ->helperText('المنتجات الموزونة لا يمكن أن تكون موديلات.'),
                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true),
                    ]),
                Section::make('الخيارات')
                    ->description('أضف محاور الموديل (مثل المقاس واللون) وقيم كل محور. ترتيب المحاور يحدد ترتيب اسم المتغير.')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->relationship('options')
                            ->orderColumn('position')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel('إضافة محور')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->schema([
                                TextInput::make('name')
                                    ->label('اسم المحور')
                                    ->placeholder('المقاس، اللون...')
                                    ->required()
                                    ->distinct()
                                    ->maxLength(255),
                                Repeater::make('values')
                                    ->label('القيم')
                                    ->relationship('values')
                                    ->orderColumn('position')
                                    ->reorderable()
                                    ->defaultItems(0)
                                    ->addActionLabel('إضافة قيمة')
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('value')
                                            ->label('القيمة')
                                            ->required()
                                            ->distinct()
                                            ->maxLength(255),
                                        TextInput::make('code')
                                            ->label('الكود')
                                            ->helperText('اختياري، يستخدم في كود الصنف.')
                                            ->maxLength(16),
                                        ColorPicker::make('color_hex')
                                            ->label('اللون')
                                            ->hex()
                                            ->rules(['nullable', 'max:9']),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('variants'))
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الموديل')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('الفئة')
                    ->sortable(),
                TextColumn::make('brand')
                    ->label('الماركة')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('sku_prefix')
                    ->label('بادئة الكود')
                    ->placeholder('-'),
                TextColumn::make('base_price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('variants_count')
                    ->label('عدد المتغيرات')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('الفئة')
                    ->relationship('category', 'name'),
                TernaryFilter::make('is_active')
                    ->label('نشط'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (ProductGroup $record): bool => ! $record->variants()->exists()),
            ]);
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductGroups::route('/'),
            'create' => CreateProductGroup::route('/create'),
            'edit' => EditProductGroup::route('/{record}/edit'),
            'stock' => ProductGroupStock::route('/{record}/stock'),
        ];
    }
}
