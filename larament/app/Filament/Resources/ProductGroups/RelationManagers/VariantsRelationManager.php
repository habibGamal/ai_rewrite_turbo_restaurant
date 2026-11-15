<?php

namespace App\Filament\Resources\ProductGroups\RelationManagers;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductGroup;
use App\Services\FeatureService;
use App\Services\Products\ProductBarcodeService;
use App\Services\Products\ProductVariantService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

/**
 * The SKUs (products rows) of a product group: per-variant SKU, barcode,
 * price override, minimum stock and active flag.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'المتغيرات';

    protected static ?string $modelLabel = 'متغير';

    protected static ?string $pluralModelLabel = 'المتغيرات';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(FeatureService::class)->variantsEnabled() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(app(FeatureService::class)->variantsEnabled(), 403);

        parent::mount();
    }

    protected function canCreate(): bool
    {
        // Variants are created by the group's generate action only.
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return app(FeatureService::class)->variantsEnabled() && parent::canEdit($record);
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    /** Re-render after the edit page generated new variants. */
    #[On('product-variants-updated')]
    public function refreshVariants(): void {}

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('sku')
                ->label('كود الصنف (SKU)')
                ->maxLength(64)
                ->unique('products', 'sku', ignoreRecord: true),
            TextInput::make('barcode')
                ->label('الباركود')
                ->maxLength(64)
                ->placeholder('اختياري')
                ->rules([
                    fn (?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                        $owner = app(ProductBarcodeService::class)->ownerOf(is_string($value) ? $value : null, $record?->getKey());
                        if ($owner !== null) {
                            $fail("الباركود مستخدم بالفعل للمنتج: {$owner->name}");
                        }
                    },
                ]),
            TextInput::make('price_override')
                ->label('سعر خاص')
                ->helperText('اتركه فارغاً ليتبع سعر الموديل.')
                ->numeric()
                ->minValue(0)
                ->prefix('ج.م'),
            TextInput::make('min_stock')
                ->label('الحد الأدنى للمخزون')
                ->numeric()
                ->minValue(0)
                ->required(),
            Toggle::make('is_active')
                ->label('نشط'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('variant_label')
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'stock_quantity' => InventoryItem::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('inventory_items.product_id', 'products.id'),
            ]))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('variant_label')
                    ->label('المتغير')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('كود الصنف')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('barcode')
                    ->label('الباركود')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable()
                    ->description(fn (Product $record): string => $record->has_custom_price ? 'سعر خاص' : 'سعر الموديل')
                    ->color(fn (Product $record): ?string => $record->has_custom_price ? 'warning' : null),
                TextColumn::make('cost')
                    ->label('التكلفة')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('min_stock')
                    ->label('الحد الأدنى')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('stock_quantity')
                    ->label('المخزون')
                    ->numeric()
                    ->sortable(),
                ToggleColumn::make('is_active')
                    ->label('نشط'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('نشط'),
                TernaryFilter::make('has_custom_price')->label('سعر خاص'),
            ])
            ->headerActions([
                Action::make('generateBarcodes')
                    ->label('توليد باركود')
                    ->icon('heroicon-o-qr-code')
                    ->requiresConfirmation()
                    ->modalDescription('سيتم إعطاء كل متغير بدون باركود باركوداً داخلياً جديداً (EAN-13).')
                    ->visible(fn (): bool => $this->canEditAny())
                    ->action(function (): void {
                        /** @var ProductGroup $group */
                        $group = $this->getOwnerRecord();
                        $count = app(ProductVariantService::class)->assignMissingBarcodes($group);

                        Notification::make()
                            ->success()
                            ->title($count > 0 ? sprintf('تم توليد %d باركود', $count) : 'كل المتغيرات لها باركود بالفعل')
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, Product $record): array {
                        $data['price_override'] = $record->has_custom_price ? $record->price : null;

                        return $data;
                    })
                    ->using(function (Product $record, array $data): Product {
                        DB::transaction(function () use ($record, $data): void {
                            $record->fill([
                                'sku' => filled($data['sku'] ?? null) ? trim((string) $data['sku']) : null,
                                'barcode' => ProductBarcode::normalize($data['barcode'] ?? null),
                                'min_stock' => $data['min_stock'] ?? 0,
                                'is_active' => (bool) ($data['is_active'] ?? true),
                            ]);

                            app(ProductVariantService::class)->setVariantPrice($record, $data['price_override'] ?? null);
                        });

                        return $record;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('setPrice')
                        ->label('تحديد سعر خاص')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            TextInput::make('price')
                                ->label('السعر')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->prefix('ج.م'),
                        ])
                        ->action(fn (Collection $records, array $data) => $this->eachVariant(
                            $records,
                            fn (Product $product) => app(ProductVariantService::class)->setVariantPrice($product, $data['price']),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('clearPrice')
                        ->label('إلغاء السعر الخاص')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $this->eachVariant(
                            $records,
                            fn (Product $product) => app(ProductVariantService::class)->setVariantPrice($product, null),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('activate')
                        ->label('تفعيل')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $this->eachVariant(
                            $records,
                            fn (Product $product) => $product->update(['is_active' => true]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('إيقاف')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(fn (Collection $records) => $this->eachVariant(
                            $records,
                            fn (Product $product) => $product->update(['is_active' => false]),
                        ))
                        ->deselectRecordsAfterCompletion(),
                ])->visible(fn (): bool => $this->canEditAny()),
            ]);
    }

    private function canEditAny(): bool
    {
        return app(FeatureService::class)->variantsEnabled()
            && (auth()->user()?->can('update', $this->getOwnerRecord()) ?? false);
    }

    /**
     * Apply a change to the selected SKUs of this group only.
     *
     * @param  Collection<int, Model>  $records
     */
    private function eachVariant(Collection $records, Closure $callback): void
    {
        $groupId = $this->getOwnerRecord()->getKey();

        DB::transaction(function () use ($records, $callback, $groupId): void {
            $records
                ->filter(fn (Model $record): bool => $record instanceof Product && (int) $record->product_group_id === (int) $groupId)
                ->each(fn (Product $product) => $callback($product));
        });

        Notification::make()->success()->title('تم التحديث')->send();
    }
}
