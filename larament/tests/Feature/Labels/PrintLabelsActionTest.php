<?php

use App\Enums\OptionalFeature;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\PurchaseInvoices\Pages\ViewPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Labels\LabelImageMaker;
use App\Labels\LabelPrintJobs;
use App\Labels\LabelRenderer;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\User;
use App\Services\FeatureService;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

require_once __DIR__.'/../../Support/VariantGrid.php';

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::LABEL_PRINTING);
    actingAs(User::factory()->admin()->create());

    // Records what would be printed instead of keeping a job.
    $this->printed = new ArrayObject;
    $printed = $this->printed;
    app()->instance(LabelPrintJobs::class, new class($printed) extends LabelPrintJobs
    {
        public function __construct(private ArrayObject $printed)
        {
            parent::__construct(app(LabelRenderer::class), app(LabelImageMaker::class));
        }

        public function forProducts(LabelTemplate $template, array $copiesByProduct): string
        {
            $this->printed['template'] = $template->id;
            $this->printed['copies'] = $copiesByProduct;

            return parent::forProducts($template, $copiesByProduct);
        }
    });
});

it('prints as many labels as each purchase invoice line bought, rounded up', function () {
    $shirt = Product::factory()->create(['barcode' => '0001']);
    $rice = Product::factory()->create(['barcode' => '0002']);
    $noBarcode = Product::factory()->create(['barcode' => null]);
    $invoice = PurchaseInvoice::factory()->create();
    PurchaseInvoiceItem::factory()->create(['purchase_invoice_id' => $invoice->id, 'product_id' => $shirt->id, 'quantity' => 12]);
    PurchaseInvoiceItem::factory()->create(['purchase_invoice_id' => $invoice->id, 'product_id' => $rice->id, 'quantity' => 2.5]);
    PurchaseInvoiceItem::factory()->create(['purchase_invoice_id' => $invoice->id, 'product_id' => $noBarcode->id, 'quantity' => 3]);

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->mountAction('printLabels')
        ->assertSchemaStateSet(fn (array $state): array => [
            'template_id' => LabelTemplate::default()->id,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->printed['copies'])->toBe([$shirt->id => 12, $rice->id => 3]);
});

it('lets the counts be changed before printing', function () {
    $shirt = Product::factory()->create(['barcode' => '0001']);
    $invoice = PurchaseInvoice::factory()->create();
    PurchaseInvoiceItem::factory()->create(['purchase_invoice_id' => $invoice->id, 'product_id' => $shirt->id, 'quantity' => 12]);

    livewire(ListPurchaseInvoices::class)
        ->callAction(TestAction::make('printLabels')->table($invoice), [
            'items' => [['product_id' => $shirt->id, 'name' => 'x', 'copies' => 5]],
        ])
        ->assertHasNoActionErrors();

    expect($this->printed['copies'])->toBe([$shirt->id => 5]);
});

it('prints one label per selected product from the product list', function () {
    $products = Product::factory()->count(2)->sequence(['barcode' => 'A1'], ['barcode' => 'A2'])->create(['type' => 'consumable']);

    livewire(ListConsumableProducts::class)
        ->callTableBulkAction('printLabels', $products)
        ->assertHasNoTableBulkActionErrors();

    expect($this->printed['copies'])->toBe([$products[0]->id => 1, $products[1]->id => 1]);
});

it('swaps the old variant label page for the label designs while the feature is on', function () {
    app(FeatureService::class)->enable(OptionalFeature::VARIANTS);
    $group = gridGroup(['المقاس' => ['S', 'M']]);
    $group->variants()->each(fn (Product $variant, int $i) => $variant->update(['barcode' => 'V'.$i]));

    livewire(EditProductGroup::class, ['record' => $group->getRouteKey()])
        ->assertActionVisible('printVariantLabels')
        ->assertActionHidden('labels')
        ->callAction('printVariantLabels')
        ->assertHasNoActionErrors();

    expect($this->printed['copies'])->toHaveCount(2);

    app(FeatureService::class)->disable(OptionalFeature::LABEL_PRINTING);

    livewire(EditProductGroup::class, ['record' => $group->getRouteKey()])
        ->assertActionHidden('printVariantLabels')
        ->assertActionVisible('labels');
});

it('hides the print buttons from users without the print permission', function () {
    $invoice = PurchaseInvoice::factory()->create();
    $user = User::factory()->create();
    $user->syncRoles([]);
    $user->givePermissionTo(['access_admin_panel', 'view_purchase_invoices']);
    actingAs($user);

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionHidden('printLabels');

    $user->givePermissionTo('print_labels');

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionVisible('printLabels');
});

it('adds the print app script to admin pages only while the feature is on', function () {
    get(LabelTemplateResource::getUrl('index'))->assertSee('turbo-label://start', false);

    app(FeatureService::class)->disable(OptionalFeature::LABEL_PRINTING);

    get(PurchaseInvoiceResource::getUrl('index'))->assertDontSee('turbo-label://start', false);
});
