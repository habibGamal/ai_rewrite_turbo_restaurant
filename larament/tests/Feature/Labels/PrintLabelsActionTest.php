<?php

use App\Enums\OptionalFeature;
use App\Filament\Resources\ConsumableProducts\Pages\ListConsumableProducts;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\Pages\ListPurchaseInvoices;
use App\Filament\Resources\PurchaseInvoices\Pages\ViewPurchaseInvoice;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\CreateReturnPurchaseInvoice;
use App\Filament\Resources\ReturnPurchaseInvoices\Pages\ViewReturnPurchaseInvoice;
use App\Filament\Resources\StockIssues\Pages\CreateStockIssue;
use App\Filament\Resources\StockIssues\Pages\ViewStockIssue;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Labels\LabelImageMaker;
use App\Labels\LabelPrintJobs;
use App\Labels\LabelRenderer;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ReturnPurchaseInvoice;
use App\Models\ReturnPurchaseInvoiceItem;
use App\Models\StockIssue;
use App\Models\StockIssueItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\FeatureService;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

require_once __DIR__.'/../../Support/VariantGrid.php';

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::LABEL_PRINTING);
    app(FeatureService::class)->enable(OptionalFeature::DOCUMENT_BARCODES);
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

it('prints labels from purchase returns, stock issues and stock transfers by line quantity', function () {
    $product = Product::factory()->create(['barcode' => 'D1', 'type' => 'consumable']);

    $return = ReturnPurchaseInvoice::factory()->create();
    ReturnPurchaseInvoiceItem::factory()->create(['return_purchase_invoice_id' => $return->id, 'product_id' => $product->id, 'quantity' => 4]);
    livewire(ViewReturnPurchaseInvoice::class, ['record' => $return->getRouteKey()])->callAction('printLabels')->assertHasNoActionErrors();
    expect($this->printed['copies'])->toBe([$product->id => 4]);

    $issue = StockIssue::factory()->create();
    StockIssueItem::factory()->create(['stock_issue_id' => $issue->id, 'product_id' => $product->id, 'quantity' => 2]);
    livewire(ViewStockIssue::class, ['record' => $issue->getRouteKey()])->callAction('printLabels')->assertHasNoActionErrors();
    expect($this->printed['copies'])->toBe([$product->id => 2]);

    $transfer = StockTransfer::factory()->create();
    StockTransferItem::factory()->create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 7]);
    livewire(ViewStockTransfer::class, ['record' => $transfer->getRouteKey()])->callAction('printLabels')->assertHasNoActionErrors();
    expect($this->printed['copies'])->toBe([$product->id => 7]);
});

it('shows the document print buttons and barcode column only while document barcodes are on', function () {
    $product = Product::factory()->create(['barcode' => 'DOC-77']);
    $invoice = PurchaseInvoice::factory()->create();
    PurchaseInvoiceItem::factory()->create(['purchase_invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1]);

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionVisible('printLabels');
    livewire(ItemsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => ViewPurchaseInvoice::class,
    ])->assertSee('DOC-77');

    app(FeatureService::class)->disable(OptionalFeature::DOCUMENT_BARCODES);

    livewire(ViewPurchaseInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertActionHidden('printLabels');
    livewire(ItemsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => ViewPurchaseInvoice::class,
    ])->assertDontSee('DOC-77');
});

it('renders the document forms with the barcode field', function (string $page) {
    livewire($page)->assertOk();
})->with([
    CreatePurchaseInvoice::class,
    CreateReturnPurchaseInvoice::class,
    CreateStockIssue::class,
    CreateStockTransfer::class,
]);

it('fills the barcode when a product is picked on a stock transfer line', function () {
    $product = Product::factory()->create(['barcode' => 'TR-9', 'type' => 'consumable']);

    $component = livewire(CreateStockTransfer::class);
    $key = array_key_first($component->get('data.items'));

    $component->set("data.items.{$key}.product_id", $product->id)
        ->assertSet("data.items.{$key}.barcode", 'TR-9');
});
