<?php

use App\Enums\OptionalFeature;
use App\Mcp\Servers\BusinessServer;
use App\Mcp\Support\AiViews;
use App\Mcp\Support\ReadOnlyQuery;
use App\Mcp\Tools\DescribeDataModelTool;
use App\Mcp\Tools\RunReadonlyQueryTool;
use App\Models\Customer;
use App\Models\McpToolCall;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

$tools = [DescribeDataModelTool::class, RunReadonlyQueryTool::class];

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    app(FeatureService::class)->enable(OptionalFeature::AI_QUERIES);
});

describe('access', function () use ($tools) {
    it('lists the query tools for an admin and the vendor while the feature is on', function () use ($tools) {
        BusinessServer::actingAs($this->admin)->tools()->assertRegistered($tools);
        BusinessServer::actingAs(User::factory()->vendor()->create())->tools()->assertRegistered($tools);
    });

    it('hides them from other roles and while the feature is off', function () use ($tools) {
        BusinessServer::actingAs(User::factory()->viewer()->create())->tools()->assertNotRegistered($tools);
        BusinessServer::actingAs(User::factory()->cashier()->create())->tools()->assertNotRegistered($tools);

        app(FeatureService::class)->disable(OptionalFeature::AI_QUERIES);
        BusinessServer::actingAs($this->admin)->tools()->assertNotRegistered($tools);
    });

    it('starts off for a new install', function () {
        expect(OptionalFeature::AI_QUERIES->settingKey()->defaultValue())->toBe('false')
            ->and(OptionalFeature::standalone())->toContain(OptionalFeature::AI_QUERIES);
    });
});

describe('views', function () {
    beforeEach(fn () => AiViews::ensure());

    it('creates every ai view and none exposes secrets', function () {
        foreach (AiViews::names() as $view) {
            $columns = DB::getSchemaBuilder()->getColumnListing($view);

            expect($columns)->not->toBeEmpty()
                ->and(array_intersect($columns, ['password', 'email', 'phone', 'address', 'remember_token', 'badge_hash', 'badge_pin']))->toBe([]);
        }
    });

    it('keeps the vendor out of ai_staff', function () {
        User::factory()->vendor()->create(['name' => 'دعم']);

        expect(DB::table('ai_staff')->pluck('name')->all())->not->toContain('دعم')
            ->and(DB::table('ai_staff')->pluck('name')->all())->toContain($this->admin->name);
    });
});

describe('run_readonly_query', function () {
    it('returns rows from the ai views with the business date', function () {
        mcpOrder('dine_in', 120, '2026-11-18 13:00:00');
        mcpOrder('takeaway', 80, '2026-11-18 14:00:00');

        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, [
            'sql' => "SELECT channel, COUNT(*) AS orders, SUM(total) AS sales FROM ai_orders WHERE status = 'completed' GROUP BY channel ORDER BY channel;",
            'purpose' => 'sales per channel',
        ]));

        expect($data['data']['columns'])->toBe(['channel', 'orders', 'sales'])
            ->and($data['data']['rows'])->toHaveCount(2)
            ->and($data['data']['rows'][0]['channel'])->toBe('dine_in')
            ->and($data['data']['truncated'])->toBeFalse()
            ->and($data['caveats'][0])->toContain('Direct query');
    });

    it('caps the rows at 500', function () {
        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, [
            'sql' => 'WITH a AS (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) SELECT x.n FROM a x, a y, a z',
            'purpose' => 'row cap',
        ]));

        expect($data['data']['row_count'])->toBe(ReadOnlyQuery::MAX_ROWS)
            ->and($data['data']['truncated'])->toBeTrue();
    });

    it('refuses anything but a select on the ai views', function (string $sql, string $message) {
        BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, ['sql' => $sql, 'purpose' => 'test'])
            ->assertHasErrors([$message]);
    })->with([
        'raw table' => ['SELECT email, password FROM users', 'not: users'],
        'table in a join' => ['SELECT o.id FROM ai_orders o JOIN customers c ON c.id = o.customer_id', 'not: customers'],
        'quoted table' => ['SELECT * FROM `settings`', 'not: settings'],
        'comma join after a subquery' => ['SELECT t.n FROM (SELECT 1 AS n) t, users', 'not: users'],
        'qualified column' => ['SELECT users.password FROM ai_staff', 'not: users'],
        'schema function' => ["SELECT name FROM pragma_table_info('users')", 'pragma_table_info'],
        'catalog' => ['SELECT name FROM sqlite_master', 'sqlite_master'],
        'write' => ['UPDATE ai_products SET price = 0', 'Only SELECT'],
        'stacked' => ['SELECT 1; DELETE FROM orders', 'exactly one SELECT'],
        'comment' => ['SELECT id FROM ai_orders -- hidden', 'comments'],
        'into outfile' => ["SELECT id FROM ai_orders INTO OUTFILE '/tmp/x'", 'into'],
        'sleep' => ['SELECT SLEEP(10)', 'sleep'],
        'recursive cte' => ['WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r) SELECT n FROM r', 'recursive'],
        'unbalanced quote' => ["SELECT id FROM ai_orders WHERE channel = 'x", 'balanced quotes'],
    ]);

    it('allows table names and keywords inside string literals', function () {
        $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, [
            'sql' => "SELECT COUNT(*) AS n FROM ai_orders WHERE channel = 'users; drop -- #'",
            'purpose' => 'literal',
        ]));

        expect($data['data']['rows'][0]['n'])->toEqual(0);
    });

    it('turns a database error into a message the model can fix', function () {
        BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, ['sql' => 'SELECT no_such_column FROM ai_orders', 'purpose' => 'typo'])
            ->assertHasErrors(['The database rejected the query']);
    });

    it('switches the connection back to read-write after the query', function () {
        app(ReadOnlyQuery::class)->run('SELECT id FROM ai_customers');

        expect((int) array_values((array) DB::selectOne('PRAGMA query_only'))[0])->toBe(0);
        $customer = Customer::factory()->create(['name' => 'بعد']);
        expect($customer->exists)->toBeTrue();
    });

    it('logs the query with its purpose', function () {
        BusinessServer::actingAs($this->admin)->tool(RunReadonlyQueryTool::class, ['sql' => 'SELECT COUNT(*) AS n FROM ai_products', 'purpose' => 'how many products']);

        $call = McpToolCall::query()->latest('id')->firstOrFail();
        expect($call->tool)->toBe('run_readonly_query')
            ->and($call->arguments['purpose'])->toBe('how many products')
            ->and($call->ok)->toBeTrue();
    });
});

it('describes every view with join hints and rules', function () {
    $data = mcpJson(BusinessServer::actingAs($this->admin)->tool(DescribeDataModelTool::class));

    expect(array_column($data['data']['views'], 'view'))->toBe(AiViews::names())
        ->and($data['data']['views'][0]['columns'])->toHaveKey('business_date')
        ->and($data['data']['rules'])->not->toBeEmpty();
});

it('prints select-only grants for every view', function () {
    Artisan::call('mcp:readonly-grants', ['username' => 'turbo_ai']);
    $output = Artisan::output();

    expect($output)->toContain("CREATE USER IF NOT EXISTS 'turbo_ai'@'localhost'")
        ->and(substr_count($output, 'GRANT SELECT ON'))->toBe(count(AiViews::names()))
        ->and($output)->not->toContain('GRANT ALL');
});
