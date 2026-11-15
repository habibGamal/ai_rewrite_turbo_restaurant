<?php

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Products\ProductVariantService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * @property ProductGroup $record
 */
class EditProductGroup extends EditRecord
{
    protected static string $resource = ProductGroupResource::class;

    /** How many labels the generate preview lists before summarising the rest. */
    private const PREVIEW_LIMIT = 60;

    protected function getHeaderActions(): array
    {
        return [
            $this->generateVariantsAction(),
            Action::make('stock')
                ->label('مصفوفة المخزون')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn (ProductGroup $record): string => ProductGroupStock::getUrl(['record' => $record]))
                ->visible(fn (ProductGroup $record): bool => $record->variants()->exists()),
            Action::make('labels')
                ->label('طباعة الملصقات')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->visible(fn (ProductGroup $record): bool => $record->variants()->exists())
                ->schema([
                    Radio::make('copies')
                        ->label('عدد الملصقات')
                        ->options([
                            'one' => 'ملصق واحد لكل صنف',
                            'stock' => 'بعدد الكمية في المخزون',
                        ])
                        ->default('one')
                        ->required(),
                ])
                ->action(function (array $data, ProductGroup $record): void {
                    $this->js('window.open('.json_encode(route('labels.group', ['group' => $record, 'copies' => $data['copies']])).', \'_blank\')');
                }),
            DeleteAction::make()
                ->visible(fn (ProductGroup $record): bool => ! $record->variants()->exists()),
        ];
    }

    /**
     * The repeaters delete removed option values through the models, which
     * refuse to delete a value a SKU uses. Show that as a form error instead
     * of a server error (the save is rolled back by the parent).
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $isFormError = collect(array_keys($errors))->every(fn (string $key): bool => str_starts_with($key, 'data.'));
            if ($isFormError) {
                throw $exception;
            }

            $message = (string) (Arr::first(Arr::flatten($errors)) ?? 'تعذر الحفظ.');

            Notification::make()
                ->danger()
                ->title('تعذر حفظ الخيارات')
                ->body($message)
                ->persistent()
                ->send();

            throw ValidationException::withMessages(['data.options' => $message]);
        }
    }

    protected function generateVariantsAction(): Action
    {
        return Action::make('generateVariants')
            ->label('توليد المتغيرات')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->modalHeading('توليد المقاسات والألوان')
            ->modalDescription('يتم التوليد من الخيارات المحفوظة. احفظ تعديلات الخيارات أولاً.')
            ->modalContent(fn (): HtmlString => $this->generatePreview())
            ->modalSubmitActionLabel('توليد')
            ->action(function (): void {
                try {
                    $created = app(ProductVariantService::class)->generate($this->record->fresh() ?? $this->record);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('تعذر توليد المتغيرات')
                        ->body((string) Arr::first(Arr::flatten($exception->errors())))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($created->isEmpty() ? 'كل المتغيرات موجودة بالفعل' : sprintf('تم إنشاء %d متغير', $created->count()))
                    ->send();

                $this->dispatch('product-variants-updated');
            });
    }

    protected function generatePreview(): HtmlString
    {
        $service = app(ProductVariantService::class);
        $group = $this->record->fresh() ?? $this->record;

        try {
            $service->assertGroupable($group);
            $combinations = $service->combinations($group);
            $incomplete = $service->incompleteVariants($group);
        } catch (ValidationException $exception) {
            return new HtmlString('<p class="text-danger-600">'.e((string) Arr::first(Arr::flatten($exception->errors()))).'</p>');
        }

        if ($combinations->isEmpty()) {
            return new HtmlString('<p>لا توجد خيارات بقيم محفوظة بعد. أضف محاور وقيماً ثم احفظ.</p>');
        }

        $toCreate = $combinations->whereNull('product')->pluck('label');
        $existing = $combinations->count() - $toCreate->count();

        $html = '<div class="space-y-3 text-sm">';
        $html .= '<p><strong>'.e((string) $toCreate->count()).'</strong> متغير جديد سيتم إنشاؤه'
            .($existing > 0 ? '، و'.e((string) $existing).' موجود بالفعل.' : '.').'</p>';
        $html .= $this->labelList($toCreate->all());

        if ($incomplete->isNotEmpty()) {
            $html .= '<p class="text-warning-600">متغيرات لا تطابق الخيارات الحالية (ستبقى كما هي ويمكنك إيقافها):</p>';
            $html .= $this->labelList($incomplete->map(fn (Product $product): string => (string) ($product->variant_label ?: $product->name))->all());
        }

        return new HtmlString($html.'</div>');
    }

    /** @param  array<int, string>  $labels */
    private function labelList(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $shown = array_slice($labels, 0, self::PREVIEW_LIMIT);
        $items = implode('', array_map(fn (string $label): string => '<li>'.e($label).'</li>', $shown));
        $more = count($labels) - count($shown);

        return '<ul class="list-disc ps-5 max-h-64 overflow-y-auto">'.$items.'</ul>'
            .($more > 0 ? '<p>و'.e((string) $more).' أخرى…</p>' : '');
    }
}
