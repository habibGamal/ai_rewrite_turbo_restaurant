<?php

use App\Enums\OptionalFeature;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Labels\LabelData;
use App\Labels\LabelImageMaker;
use App\Labels\LabelPrintJobs;
use App\Labels\LabelRenderer;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Livewire\livewire;

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::LABEL_PRINTING);

    // wkhtmltoimage is not installed in tests: a 1×1 PNG stands in.
    app()->instance(LabelImageMaker::class, new class extends LabelImageMaker
    {
        public int $calls = 0;

        public function png(LabelTemplate $template, string $html): string
        {
            $this->calls++;

            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGP4DwABAQEAG7buVgAAAABJRU5ErkJggg==');
        }
    });
});

function labelTemplate(array $attributes = []): LabelTemplate
{
    return LabelTemplate::create(array_merge([
        'name' => 'تجربة',
        'width_mm' => 38,
        'height_mm' => 25,
        'columns' => 1,
        'dpi' => 203,
        'elements' => LabelTemplate::starterElements(),
    ], $attributes));
}

function rowsOf(string $job): array
{
    $data = app(LabelPrintJobs::class)->find($job);

    return array_map(fn (array $row): array => [count($row['labels']), $row['copies']], $data['rows']);
}

it('installs a default 38×25 design', function () {
    $template = LabelTemplate::default();

    expect($template)->not->toBeNull()
        ->and($template->is_default)->toBeTrue()
        ->and($template->sizeLabel())->toBe('38×25 مم')
        ->and($template->dots(38))->toBe(304);
});

it('draws a row at printer dots with whole-dot barcode bars', function () {
    $template = labelTemplate(['columns' => 2, 'column_gap_mm' => 2]);
    $data = new LabelData('محل', 'قميص', 'L / أزرق', '0001', 450);

    $html = app(LabelRenderer::class)->rowHtml($template, [$data, null]);

    // 2 × 38 mm + 2 mm gap at 203 DPI.
    expect($html)->toContain('width: 623px; height: 200px')
        ->and($html)->toContain('قميص')
        ->and($html)->toContain('450.00 ج.م')
        ->and($html)->toContain('<svg')
        ->and(substr_count($html, 'class="label"'))->toBe(2);

    preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)"/', $html, $bars);
    expect($bars[0])->not->toBeEmpty();
});

it('replaces placeholders in fixed text', function () {
    $template = labelTemplate(['elements' => [[
        'type' => 'text', 'x' => 0, 'y' => 0, 'width' => 38, 'height' => 5,
        'font_size' => 7, 'bold' => false, 'align' => 'center', 'text' => 'السعر {price} - {variant}',
    ]]]);

    $html = app(LabelRenderer::class)->rowHtml($template, [new LabelData('محل', 'قميص', 'XL', '0002', 99.5)]);

    expect($html)->toContain('السعر 99.50 ج.م - XL');
});

it('stores runs of identical rows once with a copy count', function () {
    $a = Product::factory()->create(['barcode' => '0001', 'price' => 10]);
    $b = Product::factory()->create(['barcode' => '0002', 'price' => 20]);
    $jobs = app(LabelPrintJobs::class);

    $single = $jobs->forProducts(labelTemplate(), [$a->id => 500]);
    expect(rowsOf($single))->toBe([[1, 500]]);

    // 3 per row: A×7 then B×2 → [AAA]×2, [ABB]×1.
    $wide = $jobs->forProducts(labelTemplate(['columns' => 3]), [$a->id => 7, $b->id => 2]);
    expect(rowsOf($wide))->toBe([[3, 2], [3, 1]]);

    // Leftover short row at the end.
    $short = $jobs->forProducts(labelTemplate(['columns' => 3]), [$a->id => 4]);
    expect(rowsOf($short))->toBe([[3, 1], [1, 1]]);
});

it('serves the manifest and page images over signed links only', function () {
    $product = Product::factory()->create(['barcode' => '0001', 'price' => 10]);
    $jobs = app(LabelPrintJobs::class);
    $job = $jobs->forProducts(labelTemplate(), [$product->id => 3]);

    $manifest = getJson($jobs->manifestUrl($job))
        ->assertOk()
        ->assertJsonPath('total_labels', 3)
        ->assertJsonPath('total_pages', 3)
        ->assertJsonPath('page.width_dots', 304)
        ->assertJsonPath('pages.0.copies', 3)
        ->json();

    get($manifest['pages'][0]['image'])->assertOk()->assertHeader('Content-Type', 'image/png');

    getJson(route('labels.jobs.manifest', ['job' => $job]))->assertForbidden();
});

it('keeps signed links valid behind a proxy that changes scheme, host or port', function () {
    $product = Product::factory()->create(['barcode' => '0001', 'price' => 10]);
    $jobs = app(LabelPrintJobs::class);
    $job = $jobs->forProducts(labelTemplate(), [$product->id => 1]);

    // Signed as https://shop.example, served to PHP as http://127.0.0.1:8080.
    URL::forceRootUrl('https://shop.example');
    URL::forceScheme('https');
    $url = $jobs->manifestUrl($job);
    URL::forceRootUrl(null);
    URL::forceScheme(null);
    expect($url)->toStartWith('https://shop.example/labels/jobs/');

    $path = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    $image = getJson('http://127.0.0.1:8080'.$path)->assertOk()->json('pages.0.image');

    expect($image)->toStartWith('/labels/jobs/');
    get('http://127.0.0.1:8080'.$image)->assertOk();
});

it('hides label jobs and designs while the feature is off', function () {
    $jobs = app(LabelPrintJobs::class);
    $job = $jobs->sample(labelTemplate());
    $url = $jobs->manifestUrl($job);

    app(FeatureService::class)->disable(OptionalFeature::LABEL_PRINTING);
    actingAs(User::factory()->admin()->create());

    getJson($url)->assertNotFound();
    get(LabelTemplateResource::getUrl('index'))->assertNotFound();
});

it('opens the browser print page for users allowed to print labels', function () {
    $job = app(LabelPrintJobs::class)->sample(labelTemplate());

    actingAs(User::factory()->admin()->create());
    get(route('labels.jobs.browser', ['job' => $job]))
        ->assertOk()
        ->assertSee('@page { size: 38mm 25mm', false);

    actingAs(User::factory()->cashier()->create());
    get(route('labels.jobs.browser', ['job' => $job]))->assertForbidden();
});

it('creates a design from the admin screen and keeps one default', function () {
    actingAs(User::factory()->admin()->create());
    $old = LabelTemplate::default();

    livewire(CreateLabelTemplate::class)
        ->fillForm([
            'name' => 'ملصق 50×30',
            'width_mm' => 50,
            'height_mm' => 30,
            'columns' => 2,
            'column_gap_mm' => 3,
            'dpi' => 300,
            'is_default' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = LabelTemplate::where('name', 'ملصق 50×30')->firstOrFail();

    expect($template->columns)->toBe(2)
        ->and($template->dpi)->toBe(300)
        ->and($template->elements)->toHaveCount(6)
        ->and($template->is_default)->toBeTrue()
        ->and($old->fresh()->is_default)->toBeFalse();

    livewire(EditLabelTemplate::class, ['record' => $template->getRouteKey()])
        ->assertOk()
        ->assertSee('معاينة الملصق');
});

it('cuts the rendered image to exact size in pure black and white', function () {
    $source = imagecreatetruecolor(10, 4);
    imagefill($source, 0, 0, imagecolorallocate($source, 200, 200, 200));
    imagesetpixel($source, 1, 1, imagecolorallocate($source, 40, 40, 40));
    ob_start();
    imagepng($source);
    $png = (string) ob_get_clean();

    $out = imagecreatefromstring((new LabelImageMaker)->monochrome($png, 12, 5));

    expect(imagesx($out))->toBe(12)
        ->and(imagesy($out))->toBe(5)
        ->and(imagecolorstotal($out))->toBe(2)
        ->and(imagecolorsforindex($out, imagecolorat($out, 1, 1))['red'])->toBe(0)
        ->and(imagecolorsforindex($out, imagecolorat($out, 0, 0))['red'])->toBe(255);
});

it('explains in plain text when a label image cannot be drawn', function () {
    app()->instance(LabelImageMaker::class, new class extends LabelImageMaker
    {
        public function png(LabelTemplate $template, string $html): string
        {
            throw new RuntimeException('برنامج wkhtmltoimage غير موجود على الخادم');
        }
    });
    $jobs = app(LabelPrintJobs::class);
    $job = $jobs->sample(labelTemplate());
    $page = getJson($jobs->manifestUrl($job))->json('pages.0.image');

    get($page)
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('تعذر رسم الملصق على الخادم: برنامج wkhtmltoimage غير موجود على الخادم');
});

it('looks for wkhtmltoimage in the configured path first', function () {
    config(['services.wkhtmltoimage.path' => '/opt/custom/wkhtmltoimage']);

    expect(LabelImageMaker::candidates()[0])->toBe('/opt/custom/wkhtmltoimage');
});
