<?php

use App\Enums\SettingKey;
use App\Filament\Resources\Terminals\Pages\EditTerminal;
use App\Filament\Resources\Terminals\Pages\ListTerminals;
use App\Models\Terminal;
use App\Models\User;
use App\PrintAgent\PrintAgentException;
use App\PrintAgent\PrintAgentJob;
use App\PrintAgent\PrintAgentQueue;
use App\Services\PrintService;
use App\Services\SettingsService;
use App\Terminals\TerminalSessionResolver;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/** @return array{s: string, t: string, n: string} */
function decodePairingCode(string $code): array
{
    expect($code)->toStartWith(PrintAgentQueue::TOKEN_PREFIX);

    return json_decode(base64_decode(strtr(substr($code, 4), '-_', '+/')), true);
}

beforeEach(function () {
    config(['print_agent.wait_seconds' => 0]);
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, true);
    app(SettingsService::class)->set(SettingKey::MAX_TERMINALS->value, '3');

    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);

    $this->terminal = Terminal::query()->create(['name' => 'كاشير ١', 'print_via' => Terminal::PRINT_VIA_AGENT]);
    $this->code = app(PrintAgentQueue::class)->pair($this->terminal);
    $this->token = decodePairingCode($this->code)['t'];
});

it('makes a pairing code with the server, a token and the drawer name', function () {
    $data = decodePairingCode($this->code);

    expect($data['s'])->toBe(rtrim(url('/'), '/'))
        ->and($data['n'])->toBe('كاشير ١')
        ->and($this->terminal->fresh()->agent_token_hash)->toBe(hash('sha256', $data['t']));
});

it('refuses the app without a valid token', function () {
    $this->getJson('/api/print-agent/jobs')->assertUnauthorized();
    $this->withToken('wrong-token-wrong-token-wrong')->getJson('/api/print-agent/jobs')->assertUnauthorized();
});

it('stores what the app says about itself', function () {
    $this->withToken($this->token)->postJson('/api/print-agent/hello', [
        'version' => '1.1.0',
        'machine' => 'CASHIER-PC',
        'printers' => ['XP-80C', 'Xprinter XP-365B'],
    ])->assertOk()->assertJson(['terminal' => 'كاشير ١', 'enabled' => true]);

    $terminal = $this->terminal->fresh();

    expect($terminal->agentPrinters())->toBe(['XP-80C', 'Xprinter XP-365B'])
        ->and(app(PrintAgentQueue::class)->online($terminal))->toBeTrue();
});

it('sends the drawer kick to the app and prints when it answers', function () {
    app(PrintAgentQueue::class)->seen($this->terminal);
    $this->terminal->update(['agent_printer' => 'XP-80C']);
    app(TerminalSessionResolver::class)->setTerminal($this->terminal->fresh());

    app(PrintService::class)->openCashierDrawer();

    $job = PrintAgentJob::query()->sole();
    expect($job->kind)->toBe('drawer')
        ->and($job->printer)->toBe('XP-80C')
        ->and(base64_decode($job->payload))->toContain("\x1bp0");

    $jobs = $this->withToken($this->token)->getJson('/api/print-agent/jobs')
        ->assertOk()
        ->json('jobs');

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['data'])->toBe($job->payload)
        ->and($job->fresh()->status)->toBe(PrintAgentJob::SENT);

    // Already handed out: not sent twice.
    expect($this->withToken($this->token)->getJson('/api/print-agent/jobs')->json('jobs'))->toBe([]);

    $this->withToken($this->token)->postJson("/api/print-agent/jobs/{$job->id}", ['ok' => true])->assertOk();

    expect($job->fresh()->status)->toBe(PrintAgentJob::DONE);
});

it('tells the cashier when the app is not connected', function () {
    app(TerminalSessionResolver::class)->setTerminal($this->terminal->fresh());

    expect(fn () => app(PrintService::class)->openCashierDrawer())
        ->toThrow(PrintAgentException::class, 'مش متصل');
});

it('reports the app failure to the cashier', function () {
    $queue = app(PrintAgentQueue::class);
    $job = $queue->push($this->terminal, 'receipt', 'bytes');
    $queue->finish($job, false, 'The printer is offline');

    expect(fn () => $queue->await($job))->toThrow(PrintAgentException::class, 'The printer is offline');
});

it('drops jobs nobody picked up in time', function () {
    $queue = app(PrintAgentQueue::class);
    $old = $queue->push($this->terminal, 'receipt', 'old');
    $old->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $new = $queue->push($this->terminal, 'receipt', 'new');

    $claimed = $queue->claim($this->terminal);

    expect($claimed->modelKeys())->toBe([$new->id])
        ->and($old->fresh()->status)->toBe(PrintAgentJob::FAILED);
});

it('keeps each drawer to its own jobs', function () {
    $other = Terminal::query()->create(['name' => 'كاشير ٢', 'print_via' => Terminal::PRINT_VIA_AGENT]);
    $job = app(PrintAgentQueue::class)->push($other, 'receipt', 'x');

    expect($this->withToken($this->token)->getJson('/api/print-agent/jobs')->json('jobs'))->toBe([]);
    $this->withToken($this->token)->postJson("/api/print-agent/jobs/{$job->id}", ['ok' => true])->assertNotFound();
});

it('keeps printing from the server for drawers set to it', function () {
    $this->terminal->update(['print_via' => Terminal::PRINT_VIA_SERVER]);

    expect($this->withToken($this->token)->getJson('/api/print-agent/jobs')->json('enabled'))->toBeFalse();
});

it('stops the old app when a new pairing code is made or the drawer is unpaired', function () {
    app(PrintAgentQueue::class)->pair($this->terminal);
    $this->withToken($this->token)->getJson('/api/print-agent/jobs')->assertUnauthorized();

    app(PrintAgentQueue::class)->unpair($this->terminal);
    expect($this->terminal->fresh()->agentPaired())->toBeFalse();
});

it('saves the agent settings of a drawer', function () {
    $this->terminal->forceFill(['agent_info' => ['printers' => ['XP-80C']]])->save();

    Livewire::test(EditTerminal::class, ['record' => $this->terminal->getRouteKey()])
        ->fillForm(['print_via' => Terminal::PRINT_VIA_AGENT, 'agent_printer' => 'XP-80C'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->terminal->fresh()->agentPrinter())->toBe('XP-80C');
});

it('pairs and unpairs from the drawers list', function () {
    app(PrintAgentQueue::class)->unpair($this->terminal);

    Livewire::test(ListTerminals::class)
        ->callTableAction('pairAgent', $this->terminal)
        ->assertNotified();

    expect($this->terminal->fresh()->agentPaired())->toBeTrue();

    Livewire::test(ListTerminals::class)
        ->callTableAction('unpairAgent', $this->terminal);

    expect($this->terminal->fresh()->agentPaired())->toBeFalse();
});
