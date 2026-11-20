<?php

use App\Auth\BadgeService;
use App\Enums\OptionalFeature;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

function mcpInitialize(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
}

function enableAssistant(): void
{
    app(FeatureService::class)->enable(OptionalFeature::AI_ASSISTANT);
}

describe('MCP endpoint', function () {
    it('answers 401 with WWW-Authenticate for any Accept header, never a redirect', function (string $accept) {
        $response = $this->call('POST', '/mcp/pos', [], [], [], [
            'HTTP_ACCEPT' => $accept,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_USER_AGENT' => 'Python/3.12 aiohttp/3.9',
        ], json_encode(mcpInitialize()));

        $response->assertStatus(401);
        expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
    })->with(['*/*', 'application/json, text/event-stream']);

    it('refuses a token while the vendor feature is off', function () {
        Passport::actingAs(User::factory()->admin()->create(), ['mcp:use'], 'api');

        $this->postJson('/mcp/pos', mcpInitialize())
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden')
            ->assertJsonPath('error_description', OptionalFeature::AI_ASSISTANT->disabledErrorMessage());
    });

    it('refuses a user without use_ai_assistant', function () {
        enableAssistant();
        Passport::actingAs(User::factory()->viewer()->create(), ['mcp:use'], 'api');

        $this->postJson('/mcp/pos', mcpInitialize())->assertStatus(403);
    });

    it('serves the business server to an admin with the feature on', function () {
        enableAssistant();
        Passport::actingAs(User::factory()->admin()->create(), ['mcp:use'], 'api');

        $this->postJson('/mcp/pos', mcpInitialize(), ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Turbo POS')
            ->assertJsonPath('result.instructions', fn (string $text) => str_contains($text, 'get_business_context'));

        $tools = $this->postJson('/mcp/pos', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->json('result.tools');
        expect(collect($tools)->pluck('name'))->toContain('sales_summary', 'get_business_context')
            ->and(collect($tools)->firstWhere('name', 'sales_summary')['annotations']['readOnlyHint'])->toBeTrue();
    });

    it('requires the mcp:use scope', function () {
        enableAssistant();
        Passport::actingAs(User::factory()->admin()->create(), ['other'], 'api');

        $this->postJson('/mcp/pos', mcpInitialize())->assertStatus(403);
    });
});

describe('OAuth discovery and registration', function () {
    it('publishes protected-resource and authorization-server metadata', function () {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/pos')
            ->assertOk()
            ->assertJsonPath('scopes_supported', ['mcp:use']);

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonStructure(['registration_endpoint', 'authorization_endpoint', 'token_endpoint']);
    });

    it('registers known AI clients and rejects unknown redirect hosts', function (array $uris, bool $allowed) {
        $response = $this->postJson('/oauth/register', [
            'client_name' => 'test',
            'redirect_uris' => $uris,
            'token_endpoint_auth_method' => 'none',
        ]);

        $allowed ? $response->assertSuccessful() : $response->assertStatus(400);
    })->with([
        'ChatGPT' => [['https://chatgpt.com/connector/oauth/abc123'], true],
        'Claude' => [['https://claude.ai/api/mcp/auth_callback'], true],
        'Gemini (six URIs)' => [[
            'https://oauth-redirect.googleusercontent.com/r/user_bound_custom-mcp-x',
            'https://oauth-redirect-sandbox.googleusercontent.com/r/user_bound_custom-mcp-x',
            'https://oauth-redirect-test.googleusercontent.com/r/user_bound_custom-mcp-x',
            'https://oauth-redirect.googleusercontent.com/a/user_bound_custom-mcp-x',
            'https://oauth-redirect-sandbox.googleusercontent.com/a/user_bound_custom-mcp-x',
            'https://oauth-redirect-test.googleusercontent.com/a/user_bound_custom-mcp-x',
        ], true],
        'Claude Code (localhost)' => [['http://localhost:53682/callback'], true],
        'look-alike host' => [['https://chatgpt.com.evil.example/cb'], false],
        'one bad URI in the set' => [['https://claude.ai/cb', 'https://evil.example/cb'], false],
    ]);
});

describe('consent page', function () {
    beforeEach(function () {
        Artisan::call('passport:keys', ['--force' => true]);
        $this->client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('ChatGPT', ['https://chatgpt.com/connector/oauth/abc'], false);
        $this->query = http_build_query([
            'client_id' => $this->client->id,
            'redirect_uri' => 'https://chatgpt.com/connector/oauth/abc',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]);
    });

    it('sends a signed-out user to the login page', function () {
        auth()->guard('web')->logout();

        $this->get('/oauth/authorize?'.$this->query)->assertRedirect('/admin/login');
    });

    it('shows the Arabic consent page to an allowed user', function () {
        enableAssistant();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/oauth/authorize?'.$this->query)
            ->assertOk()
            ->assertSee('موافقة')
            ->assertSee('لا يستطيع التطبيق تعديل أي بيانات');
    });

    it('refuses while the feature is off, without a permission, or from a badge session', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/oauth/authorize?'.$this->query)
            ->assertStatus(403)->assertSee('ميزة المساعد الذكي غير مفعلة حالياً.');

        enableAssistant();
        $this->actingAs(User::factory()->viewer()->create())->get('/oauth/authorize?'.$this->query)
            ->assertStatus(403)->assertSee('استخدام المساعد الذكي');

        $this->actingAs($admin)->withSession([BadgeService::SESSION_KEY => true])
            ->get('/oauth/authorize?'.$this->query)
            ->assertStatus(403)->assertSee('الدخول بالبادج');
    });
});
