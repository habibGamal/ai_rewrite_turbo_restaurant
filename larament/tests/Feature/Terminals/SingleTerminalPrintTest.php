<?php

use App\Enums\SettingKey;
use App\Models\Terminal;
use App\Models\User;
use App\PrintAgent\PrintAgentJob;
use App\PrintAgent\PrintAgentQueue;
use App\Services\PrintService;
use App\Services\SettingsService;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config(['print_agent.wait_seconds' => 0]);
    app(SettingsService::class)->set(SettingKey::FEATURE_MULTI_TERMINAL_ENABLED->value, false);

    actingAs(User::factory()->admin()->create());
});

it('prints through the hidden default sales point when multiple terminals are off', function () {
    $terminal = Terminal::default();
    $terminal->update(['print_via' => Terminal::PRINT_VIA_AGENT, 'agent_printer' => 'XP-80C']);
    app(PrintAgentQueue::class)->pair($terminal->fresh());
    app(PrintAgentQueue::class)->seen($terminal->fresh());

    app(PrintService::class)->openCashierDrawer();

    $job = PrintAgentJob::query()->sole();
    expect($job->kind)->toBe('drawer')
        ->and($job->terminal_id)->toBe($terminal->id)
        ->and($job->printer)->toBe('XP-80C');
});

it('runs the cashier printer test without crashing when multiple terminals are off', function () {
    Terminal::default()->update(['print_via' => Terminal::PRINT_VIA_AGENT]);
    app(PrintAgentQueue::class)->pair(Terminal::default());
    app(PrintAgentQueue::class)->seen(Terminal::default());

    app(PrintService::class)->testCashierPrinter();

    expect(PrintAgentJob::query()->where('kind', 'test')->exists())->toBeTrue();
});
