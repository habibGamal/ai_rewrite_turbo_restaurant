<?php

namespace App\Filament\Actions\Forms;

use App\Models\Product;
use App\Services\FeatureService;
use App\Services\Products\SequentialBarcodeService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Purchase invoice button: every line whose product has no barcode gets the
 * next shop barcode in the series (0001, 0002, ...), saved on the product
 * straight away, so labels can be printed for the delivery. Only shown while
 * the vendor has switched purchase barcodes on.
 */
class GeneratePurchaseBarcodesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'generatePurchaseBarcodes';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('توليد باركود للأصناف')
            ->icon('heroicon-m-qr-code')
            ->color('success')
            ->visible(fn (): bool => app(FeatureService::class)->purchaseBarcodesEnabled()
                && (auth()->user()?->can('update_products') ?? false))
            ->requiresConfirmation()
            ->modalHeading('توليد باركود للأصناف')
            ->modalDescription(function (Get $get): string {
                $missing = self::missingProductIds($get);
                if ($missing === []) {
                    return 'كل أصناف الفاتورة لها باركود بالفعل.';
                }

                $first = app(SequentialBarcodeService::class)->preview();

                return 'سيتم إعطاء '.count($missing).' صنف بدون باركود باركوداً مسلسلاً يبدأ من '.$first.'، ويُحفظ على المنتج مباشرة.';
            })
            ->modalSubmitActionLabel('توليد')
            ->action(function (Get $get, Set $set): void {
                $missing = self::missingProductIds($get);
                if ($missing === []) {
                    Notification::make()->info()->title('كل أصناف الفاتورة لها باركود بالفعل')->send();

                    return;
                }

                try {
                    $assigned = app(SequentialBarcodeService::class)->assignMissing($missing);
                } catch (\RuntimeException $e) {
                    Notification::make()->danger()->title('تعذر توليد الباركود')->body($e->getMessage())->send();

                    return;
                }

                $items = collect($get('items') ?? [])
                    ->map(function (array $item) use ($assigned): array {
                        $productId = (int) ($item['product_id'] ?? 0);
                        if (isset($assigned[$productId])) {
                            $item['barcode'] = $assigned[$productId];
                        }

                        return $item;
                    })
                    ->all();
                $set('items', $items);

                Notification::make()
                    ->success()
                    ->title('تم توليد '.count($assigned).' باركود')
                    ->body($assigned === [] ? null : 'من '.reset($assigned).' إلى '.end($assigned))
                    ->send();
            });
    }

    /**
     * Products on the invoice lines that have no barcode, in line order.
     *
     * @return list<int>
     */
    public static function missingProductIds(Get $get): array
    {
        $ids = collect($get('items') ?? [])
            ->map(fn ($item): int => (int) (is_array($item) ? ($item['product_id'] ?? 0) : 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $missing = Product::query()
            ->whereKey($ids)
            ->where(fn ($q) => $q->whereNull('barcode')->orWhere('barcode', ''))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_filter($ids, fn (int $id): bool => in_array($id, $missing, true)));
    }
}
