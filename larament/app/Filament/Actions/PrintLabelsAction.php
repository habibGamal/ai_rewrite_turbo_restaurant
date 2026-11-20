<?php

namespace App\Filament\Actions;

use App\Labels\LabelPrintJobs;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Services\FeatureService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * «طباعة ملصقات»: pick a label design and how many labels of each product,
 * then the page hands the job to the Windows print app (or the browser).
 * The products and starting counts come from where the button is: the
 * purchase invoice's quantities, a model's variants, selected products.
 */
final class PrintLabelsAction
{
    /**
     * @param  Closure(mixed): array<int|string, mixed>  $copies  product id => starting number of labels
     */
    public static function make(Closure $copies, string $name = 'printLabels'): Action
    {
        return self::configure(Action::make($name))
            ->fillForm(fn (Model $record): array => self::formState($copies($record)));
    }

    /**
     * For a document with product lines (purchase invoice, purchase return,
     * stock issue, stock transfer): as many labels as each line's quantity.
     * Shown only while the vendor has switched document barcodes on.
     */
    public static function document(string $name = 'printLabels'): Action
    {
        return self::make(
            // Every document here has an items() relation of product lines.
            fn (Model $record): array => $record->getRelationValue('items')
                ->groupBy('product_id')
                ->map(fn ($items): float => (float) $items->sum('quantity'))
                ->all(),
            $name,
        )->visible(fn (): bool => self::allowed() && app(FeatureService::class)->documentBarcodesEnabled());
    }

    /**
     * For a table's selected products: one label each to start.
     */
    public static function bulk(string $name = 'printLabels'): BulkAction
    {
        return self::configure(BulkAction::make($name))
            ->fillForm(fn (Collection $records): array => self::formState(
                $records->mapWithKeys(fn (Model $record): array => [(int) $record->getKey() => 1])->all(),
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @template T of Action
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(Action $action): Action
    {
        return $action
            ->label('طباعة ملصقات')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading('طباعة ملصقات الباركود')
            ->modalSubmitActionLabel('طباعة')
            ->modalWidth('2xl')
            ->schema([
                Select::make('template_id')
                    ->label('التصميم')
                    ->options(fn (): array => LabelTemplate::query()->orderByDesc('is_default')->orderBy('name')->get()
                        ->mapWithKeys(fn (LabelTemplate $template): array => [$template->id => $template->name.' ('.$template->sizeLabel().')'])
                        ->all())
                    ->required()
                    ->selectablePlaceholder(false),
                Repeater::make('items')
                    ->label('الأصناف')
                    ->addable(false)
                    ->reorderable(false)
                    ->columns(3)
                    ->schema([
                        Hidden::make('product_id'),
                        TextInput::make('name')
                            ->label('الصنف')
                            ->disabled()
                            ->columnSpan(2),
                        TextInput::make('copies')
                            ->label('عدد الملصقات')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(LabelPrintJobs::MAX_LABELS)
                            ->required(),
                    ]),
            ])
            ->action(function (array $data, Component $livewire): void {
                $template = LabelTemplate::find($data['template_id'] ?? null);
                $wanted = [];
                foreach ($data['items'] ?? [] as $item) {
                    $count = (int) ($item['copies'] ?? 0);
                    if ($count > 0 && isset($item['product_id'])) {
                        $wanted[(int) $item['product_id']] = ($wanted[(int) $item['product_id']] ?? 0) + $count;
                    }
                }

                if ($template === null || $wanted === []) {
                    Notification::make()->title('لا توجد ملصقات للطباعة')->warning()->send();

                    return;
                }

                $jobs = app(LabelPrintJobs::class);
                $job = $jobs->forProducts($template, $wanted);

                $livewire->js(sprintf(
                    'window.turboLabels && window.turboLabels.print(%s, %s)',
                    json_encode($jobs->manifestUrl($job)),
                    json_encode(route('labels.jobs.browser', ['job' => $job])),
                ));
            });
    }

    /**
     * @param  array<int, int|float>  $copies
     * @return array<string, mixed>
     */
    private static function formState(array $copies): array
    {
        return [
            'template_id' => LabelTemplate::default()?->id,
            'items' => self::rows($copies),
        ];
    }

    public static function allowed(): bool
    {
        return app(FeatureService::class)->labelPrintingEnabled()
            && (bool) auth()->user()?->can('print_labels');
    }

    /**
     * Form rows for products that have a barcode; counts are rounded up
     * (2.5 kg bought still needs 3 labels).
     *
     * @param  array<int, int|float>  $copies
     * @return list<array{product_id: int, name: string, copies: int}>
     */
    public static function rows(array $copies): array
    {
        if ($copies === []) {
            return [];
        }

        $products = Product::query()->with('group')->whereKey(array_keys($copies))
            ->whereNotNull('barcode')->where('barcode', '!=', '')
            ->get()->keyBy('id');

        $rows = [];
        foreach ($copies as $id => $count) {
            /** @var Product|null $product */
            $product = $products->get($id);
            if ($product === null) {
                continue;
            }

            $name = $product->group !== null && filled($product->variant_label)
                ? $product->group->name.' - '.$product->variant_label
                : $product->name;

            $rows[] = [
                'product_id' => $product->id,
                'name' => $name.' ('.$product->barcode.')',
                'copies' => max(0, (int) ceil((float) $count)),
            ];
        }

        return $rows;
    }
}
