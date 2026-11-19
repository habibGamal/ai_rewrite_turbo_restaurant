<?php

use App\Enums\OptionalFeature;
use App\Filament\Pages\AiAssistant\AiConnections;
use App\Filament\Pages\AiAssistant\AiToolCallLog;
use App\Mcp\Servers\BusinessServer;
use App\Mcp\Tools\SalesSummaryTool;
use App\Models\McpToolCall;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

function mcpConnect(User $user, string $clientName = 'Claude', ?string $expires = null, ?string $refreshExpires = null): Token
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($clientName, ['https://claude.ai/api/mcp/auth_callback'], false);
    $id = Str::random(40);
    DB::table('oauth_access_tokens')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'client_id' => $client->getKey(),
        'scopes' => json_encode(['mcp:use']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => $expires ?? now()->addHours(8),
    ]);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(40),
        'access_token_id' => $id,
        'revoked' => false,
        'expires_at' => $refreshExpires ?? now()->addDays(30),
    ]);

    return Token::query()->findOrFail($id);
}

beforeEach(function () {
    app(FeatureService::class)->enable(OptionalFeature::AI_ASSISTANT);
});

describe('tool call log', function () {
    it('records each tool call with its arguments, result and duration', function () {
        $admin = User::factory()->admin()->create();

        BusinessServer::actingAs($admin)->tool(SalesSummaryTool::class, ['period' => 'today'])->assertOk();
        BusinessServer::actingAs($admin)->tool(SalesSummaryTool::class, ['from' => '2026-01-01', 'to' => '2027-06-01'])->assertHasErrors();

        $calls = McpToolCall::query()->orderBy('id')->get();
        expect($calls)->toHaveCount(2)
            ->and($calls[0]->tool)->toBe('sales_summary')
            ->and($calls[0]->user_id)->toBe($admin->id)
            ->and($calls[0]->arguments)->toBe(['period' => 'today'])
            ->and($calls[0]->ok)->toBeTrue()
            ->and($calls[0]->error)->toBeNull()
            ->and($calls[1]->ok)->toBeFalse()
            ->and($calls[1]->error)->toContain('366');
    });

    it('is shown to admins only, and only while the feature is on', function () {
        $admin = User::factory()->admin()->create();
        McpToolCall::query()->create(['user_id' => $admin->id, 'client_name' => 'Claude', 'tool' => 'sales_summary', 'ok' => true, 'duration_ms' => 12]);

        actingAs($admin);
        livewire(AiToolCallLog::class)->assertOk()->assertCanSeeTableRecords(McpToolCall::all());

        actingAs(User::factory()->viewer()->create());
        get(AiToolCallLog::getUrl())->assertForbidden();

        actingAs($admin);
        app(FeatureService::class)->disable(OptionalFeature::AI_ASSISTANT);
        get(AiToolCallLog::getUrl())->assertForbidden();
    });
});

describe('connected apps page', function () {
    it('opens for users who may use the assistant and shows the server URL', function () {
        actingAs(User::factory()->admin()->create());

        get(AiConnections::getUrl())->assertOk()->assertSee(url('/mcp/pos'))->assertSee('طريقة الربط');
    });

    it('is hidden when the feature is off or the user lacks the permission', function () {
        actingAs(User::factory()->viewer()->create());
        get(AiConnections::getUrl())->assertForbidden();

        actingAs(User::factory()->admin()->create());
        app(FeatureService::class)->disable(OptionalFeature::AI_ASSISTANT);
        get(AiConnections::getUrl())->assertForbidden();
    });

    it('lists live connections, everyone for an admin and only their own for others', function () {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->viewer()->create();
        $other->givePermissionTo('use_ai_assistant');

        $mine = mcpConnect($admin);
        $theirs = mcpConnect($other, 'ChatGPT');
        $refreshed = mcpConnect($admin, 'Google', now()->subHour()->toDateTimeString());
        $dead = mcpConnect($admin, 'Old', now()->subHour()->toDateTimeString(), now()->subDay()->toDateTimeString());

        actingAs($admin);
        livewire(AiConnections::class)
            ->assertCanSeeTableRecords([$mine, $theirs, $refreshed])
            ->assertCanNotSeeTableRecords([$dead]);

        actingAs($other);
        livewire(AiConnections::class)
            ->assertCanSeeTableRecords([$theirs])
            ->assertCanNotSeeTableRecords([$mine, $refreshed]);
    });

    it('revokes a connection with its refresh token', function () {
        $admin = User::factory()->admin()->create();
        $token = mcpConnect($admin);
        actingAs($admin);

        livewire(AiConnections::class)->callTableAction('revoke', $token);

        expect($token->fresh()->revoked)->toBeTrue()
            ->and(DB::table('oauth_refresh_tokens')->where('access_token_id', $token->id)->value('revoked'))->toBeTruthy();
    });

    it('revokes only the user\'s own connections with revoke all', function () {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->viewer()->create();
        $other->givePermissionTo('use_ai_assistant');
        $mine = mcpConnect($admin);
        $theirs = mcpConnect($other);

        actingAs($other);
        livewire(AiConnections::class)->callAction('revokeAll');

        expect($theirs->fresh()->revoked)->toBeTrue()
            ->and($mine->fresh()->revoked)->toBeFalse();
    });
});

it('prunes tool call rows older than 180 days', function () {
    McpToolCall::query()->create(['tool' => 'sales_summary', 'ok' => true, 'duration_ms' => 1])->forceFill(['created_at' => now()->subDays(181)])->save();
    McpToolCall::query()->create(['tool' => 'sales_breakdown', 'ok' => true, 'duration_ms' => 1]);

    $this->artisan('model:prune', ['--model' => [McpToolCall::class]])->assertSuccessful();

    expect(McpToolCall::query()->pluck('tool')->all())->toBe(['sales_breakdown']);
});
