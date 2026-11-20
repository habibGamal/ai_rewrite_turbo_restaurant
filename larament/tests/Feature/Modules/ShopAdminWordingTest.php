<?php

use App\Enums\OrderStatus;
use App\Filament\Pages\SetupWizard;
use App\Filament\Pages\VendorConsole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Modules\BusinessType;
use App\Modules\ModuleScreens;
use App\Orders\OrderTypeCatalog;
use App\Services\FeatureService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The leak check for Phase 5: a shop install must not show restaurant words
 * (or terminal words while multi-terminal does not exist) anywhere in the
 * admin panel it can open, and every screen of a switched-off module must
 * answer 404.
 */

const RESTAURANT_WORDS = ['مطعم', 'مطبخ', 'طاولة', 'طاولات', 'صالة', 'ترابيزة'];
const TERMINAL_WORDS = ['ترمينال', 'طرفية'];

/**
 * Visible text of a rendered page: scripts, styles and attributes removed.
 */
function visibleText(string $html): string
{
    $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#si', ' ', $html) ?? $html;

    return html_entity_decode(strip_tags($html));
}

/**
 * @return list<string>
 */
function leakedWords(string $text): array
{
    $found = [];
    foreach ([...RESTAURANT_WORDS, ...TERMINAL_WORDS] as $word) {
        $offset = 0;
        while (($pos = mb_strpos($text, $word, $offset)) !== false) {
            $found[] = trim(mb_substr($text, max(0, $pos - 30), 70 + mb_strlen($word)));
            $offset = $pos + 1;
        }
    }

    return $found;
}

/**
 * Index and create URLs of every resource and every page without route
 * parameters, keyed by owning class.
 *
 * @return array<string, array{class: class-string, url: string}>
 */
function adminScreenUrls(): array
{
    $panel = Filament::getPanel('admin');
    $urls = [];

    foreach ($panel->getResources() as $resource) {
        /** @var class-string<resource> $resource */
        foreach (['index', 'create'] as $name) {
            if ($resource::hasPage($name)) {
                $urls[$resource.'@'.$name] = ['class' => $resource, 'url' => $resource::getUrl($name, panel: 'admin')];
            }
        }

        // Record pages, for the resources that have a row to open.
        $record = rescue(fn () => $resource::getEloquentQuery()->first(), report: false);
        foreach (['view', 'edit'] as $name) {
            if ($record !== null && $resource::hasPage($name)) {
                $urls[$resource.'@'.$name] = ['class' => $resource, 'url' => $resource::getUrl($name, ['record' => $record], panel: 'admin')];
            }
        }
    }

    foreach ($panel->getPages() as $page) {
        /** @var class-string<Page> $page */
        $path = $page::getRoutePath($panel);
        if (str_contains($path, '{')) {
            continue;
        }
        $urls[$page] = ['class' => $page, 'url' => $page::getUrl(panel: 'admin')];
    }

    return $urls;
}

/**
 * A sale, a customer and a product, so tables render their columns.
 */
function seedShopData(User $cashier): void
{
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);
    $customer = Customer::factory()->create();
    $type = app(OrderTypeCatalog::class)->active()->first()->code;
    $order = Order::factory()->create([
        'type' => $type,
        'user_id' => $cashier->id,
        'customer_id' => $customer->id,
        'status' => OrderStatus::COMPLETED->value,
        'dine_table_number' => null,
        'kitchen_notes' => null,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
    app(OrderTypeCatalog::class)->flush();
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

dataset('shop types', [BusinessType::FASHION, BusinessType::GROCERY, BusinessType::GENERAL_RETAIL]);

it('shows no restaurant or terminal words in the admin panel of a shop', function (BusinessType $type) {
    app(FeatureService::class)->setBusinessType($type);
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    actingAs($admin);
    seedShopData($admin);

    $leaks = [];
    foreach (adminScreenUrls() as $key => ['class' => $class, 'url' => $url]) {
        $response = get($url);

        if (in_array($class, [VendorConsole::class, SetupWizard::class], true)) {
            continue; // vendor-only screens, 403 for the customer's admin
        }

        if (! ModuleScreens::allows($class)) {
            expect($response->status())->toBe(404, "{$key} should be hidden for {$type->value}");

            continue;
        }

        // Some pages redirect (branch management on a single node); none may fail.
        expect($response->status())->toBeLessThan(400, "{$key} should open for {$type->value}");
        if ($response->status() !== 200) {
            continue;
        }

        foreach (leakedWords(visibleText($response->getContent())) as $context) {
            $leaks[] = "{$key}: …{$context}…";
        }
    }

    expect($leaks)->toBe([]);
})->with('shop types');
