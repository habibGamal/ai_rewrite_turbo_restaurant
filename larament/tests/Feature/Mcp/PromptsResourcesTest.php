<?php

use App\Mcp\Prompts\PlaybookPrompt;
use App\Mcp\Resources\BusinessProfileResource;
use App\Mcp\Resources\GlossaryResource;
use App\Mcp\Servers\BusinessServer;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestListResponse;

require_once __DIR__.'/helpers.php';

const MCP_PLAYBOOKS = ['cash_reconciliation', 'customer_retention', 'daily_briefing', 'inventory_health', 'loss_prevention', 'menu_engineering', 'month_review', 'staffing_by_hour'];

/** @return list<string> */
function mcpListNames(TestListResponse $list): array
{
    $names = array_column((fn () => $this->items)->call($list), 'name');
    sort($names);

    return $names;
}

/** The playbooks the server would build, by name. */
function mcpPlaybook(string $name): PlaybookPrompt
{
    return collect(PlaybookPrompt::all(BusinessServer::TOOLS))->firstOrFail(fn (PlaybookPrompt $prompt) => $prompt->name() === $name);
}

it('offers all eight playbooks to an admin', function () {
    expect(mcpListNames(BusinessServer::actingAs(User::factory()->admin()->create())->prompts()))->toBe(MCP_PLAYBOOKS);
});

it('hides playbooks whose tools the user cannot call', function () {
    expect(mcpListNames(BusinessServer::actingAs(User::factory()->cashier()->create())->prompts()))->toBe([]);
});

it('resolves every required tool and has a description', function () {
    foreach (MCP_PLAYBOOKS as $name) {
        $prompt = mcpPlaybook($name);

        expect($prompt->requiredTools())->not->toBeEmpty()
            ->and($prompt->description())->not->toBeEmpty()
            ->and($prompt->title())->not->toBe($name);
    }
});

it('returns the playbook steps and the requested period', function () {
    $admin = User::factory()->admin()->create();

    BusinessServer::actingAs($admin)->prompt(mcpPlaybook('daily_briefing'))
        ->assertOk()
        ->assertSee(['get_business_context', 'Answer shape'])
        ->assertDontSee('Period the user asked for');

    BusinessServer::actingAs($admin)->prompt(mcpPlaybook('month_review'), ['period' => 'October 2026'])
        ->assertSee('Period the user asked for: October 2026');
});

it('rejects a playbook that names an unknown tool', function () {
    $path = tempnam(sys_get_temp_dir(), 'playbook').'.md';
    file_put_contents($path, "---\nname: broken\nrequires: no_such_tool\n---\nBody");

    expect(fn () => PlaybookPrompt::fromFile($path, []))->toThrow(RuntimeException::class, 'no_such_tool');

    unlink($path);
});

it('serves the glossary and the business profile to an admin only', function () {
    $admin = User::factory()->admin()->create();

    expect(mcpListNames(BusinessServer::actingAs($admin)->resources()))->toBe(['business_profile', 'glossary'])
        ->and(mcpListNames(BusinessServer::actingAs(User::factory()->cashier()->create())->resources()))->toBe([]);

    BusinessServer::actingAs($admin)->resource(GlossaryResource::class)
        ->assertOk()
        ->assertSee(['صافي المبيعات', 'loss rate', 'منفذ بيع']);

    BusinessServer::actingAs($admin)->resource(BusinessProfileResource::class)
        ->assertOk()
        ->assertSee(['business_type', 'timezone']);
});
