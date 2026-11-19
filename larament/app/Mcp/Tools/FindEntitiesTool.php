<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\CustomersPerformanceReport;
use App\Mcp\Support\ArabicText;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Terminal;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find_entities')]
#[Title('Find products, customers, staff...')]
#[Description(<<<'TEXT'
Finds products, categories, customers, staff, suppliers, warehouses or sales points by name (Arabic spelling variants are tolerated) or, for customers, by phone digits. Returns ids to pass to other tools.
Use whenever the user names something, before calling another tool with it. If several rows match, ask the user which one they mean.
Examples: «شاورما فراخ», «العميل صاحب الرقم 0100», "the cashier called Mona".
TEXT)]
#[IsReadOnly]
class FindEntitiesTool extends BusinessTool
{
    public const KINDS = ['product', 'category', 'customer', 'staff', 'supplier', 'warehouse', 'terminal'];

    protected function reportPages(): array
    {
        return self::ANY_REPORT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('Name (or part of it); for customers also phone digits.'),
            'kinds' => $schema->array()->items($schema->string()->enum(self::KINDS))
                ->description('What to search. Default: product, category, customer, staff.'),
            'limit' => $schema->integer()->description('Matches per kind, default 10, max '.self::MAX_LIMIT.'.'),
        ];
    }

    protected function run(Request $request): array
    {
        $search = trim((string) $request->get('query'));
        if (mb_strlen($search) < 2) {
            throw new InvalidArgumentException('query needs at least 2 characters.');
        }

        $kinds = array_values(array_intersect((array) ($request->get('kinds') ?? ['product', 'category', 'customer', 'staff']), self::KINDS));
        if ($kinds === []) {
            throw new InvalidArgumentException('kinds must contain any of: '.implode(', ', self::KINDS).'.');
        }

        $limit = $this->limit($request);
        $results = [];
        $caveats = [];

        foreach ($kinds as $kind) {
            if ($kind === 'customer' && ! CustomersPerformanceReport::canAccess()) {
                $caveats[] = 'Customers were not searched: no access to the customers report.';

                continue;
            }
            $results[$kind] = $this->search($kind, $search, $limit);
        }

        return $this->envelope(
            null,
            ['query' => $search, 'matches' => $results],
            ['phone' => 'Masked; ask for one customer to discuss them, never list phone numbers.'],
            $caveats,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $kind, string $search, int $limit): array
    {
        return match ($kind) {
            'product' => ArabicText::whereMatches(Product::query(), 'products.name', $search)
                ->with('category:id,name')
                ->orderByRaw('LENGTH(products.name)')
                ->limit($limit)
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->raw_type,
                    'category' => $p->category?->name,
                    'price' => round((float) $p->price, 2),
                ])->all(),
            'category' => ArabicText::whereMatches(Category::query(), 'name', $search)
                ->with('parent:id,name')
                ->limit($limit)
                ->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'parent' => $c->parent?->getAttribute('name')])
                ->all(),
            'customer' => $this->customers($search, $limit),
            'staff' => ArabicText::whereMatches(User::query(), 'name', $search)
                ->with('roles:id,name')
                ->limit($limit)
                ->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'roles' => $u->roles->pluck('name')->all()])
                ->all(),
            'supplier' => ArabicText::whereMatches(Supplier::query(), 'name', $search)
                ->limit($limit)->get(['id', 'name'])->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'warehouse' => ArabicText::whereMatches(Warehouse::query(), 'name', $search)
                ->limit($limit)->get(['id', 'name', 'is_active'])
                ->map(fn (Warehouse $w) => ['id' => $w->id, 'name' => $w->name, 'active' => (bool) $w->is_active])->all(),
            'terminal' => ArabicText::whereMatches(Terminal::query(), 'name', $search)
                ->limit($limit)->get(['id', 'name', 'is_active'])
                ->map(fn (Terminal $t) => ['id' => $t->id, 'name' => $t->name, 'active' => (bool) $t->is_active])->all(),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customers(string $search, int $limit): array
    {
        $digits = preg_replace('/\D/', '', $search) ?? '';
        $query = Customer::query();

        if (strlen($digits) >= 4 && strlen($digits) >= mb_strlen(preg_replace('/\s/u', '', $search) ?? '') - 1) {
            $query->where('phone', 'like', '%'.$digits.'%');
        } else {
            ArabicText::whereMatches($query, 'name', $search);
        }

        return $query->limit($limit)->get(['id', 'name', 'phone', 'region'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => self::maskPhone((string) $c->phone),
                'region' => $c->region,
            ])->all();
    }

    public static function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if (mb_strlen($phone) <= 6) {
            return str_repeat('*', mb_strlen($phone));
        }

        return mb_substr($phone, 0, 3).str_repeat('*', mb_strlen($phone) - 6).mb_substr($phone, -3);
    }
}
