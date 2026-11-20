<?php

use App\Enums\SettingKey;
use App\Filament\Resources\Terminals\Pages\EditTerminal;
use App\Filament\Resources\Terminals\Pages\ListTerminals;
use App\Models\Order;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Terminal;
use App\Models\User;
use App\PrintAgent\PrintAgentException;
use App\PrintAgent\PrintAgentJob;
use App\PrintAgent\PrintAgentQueue;
use App\Services\PrintService;
use App\Services\PrintStrategies\PrintStrategyInterface;
use App\Services\SettingsService;
use App\Terminals\TerminalSessionResolver;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/** @return array{s: string, t: string, n: string, k?: string} */
function decodePairingCode(string $code): array
{
    expect($code)->toStartWith(PrintAgentQueue::TOKEN_PREFIX);

    return json_decode(base64_decode(strtr(substr($code, 4), '-_', '+/')), true);
}

function fakePngPrintStrategy(): PrintStrategyInterface
{
    return new class implements PrintStrategyInterface
    {
        public function generateImageFromHtml(string $html, int $width = 572, int $height = 1200): string
        {
            $path = tempnam(sys_get_temp_dir(), 'kitchen_test_').'.png';
            $image = imagecreatetruecolor(8, 8);
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefill($image, 0, 0, $white);
            imagepng($image, $path);
            imagedestroy($image);

            return $path;
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getName(): string
        {
            return 'test-gd';
        }
    };
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
        ->toThrow(PrintAgentException::class, 'غير متصل');
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

it('pairs a kitchen printer and stores its reported printers and online status', function () {
    $printer = Printer::factory()->create([
        'name' => 'طابعة الشواية',
        'ip_address' => null,
        'print_via' => Printer::PRINT_VIA_AGENT,
    ]);

    $code = app(PrintAgentQueue::class)->pair($printer);
    $data = decodePairingCode($code);

    expect($data['s'])->toBe(rtrim(url('/'), '/'))
        ->and($data['n'])->toBe('طابعة الشواية')
        ->and($data['k'])->toBe('printer:'.$printer->id)
        ->and($printer->fresh()->agent_token_hash)->toBe(hash('sha256', $data['t']));

    $this->withToken($data['t'])->postJson('/api/print-agent/hello', [
        'version' => '1.2.0',
        'machine' => 'KITCHEN-PC',
        'printers' => ['Kitchen-XP80', 'Drinks-XP80'],
    ])->assertOk()->assertJson([
        'terminal' => 'طابعة الشواية',
        'enabled' => true,
    ]);

    $printer->refresh();
    expect($printer->agentPrinters())->toBe(['Kitchen-XP80', 'Drinks-XP80'])
        ->and(app(PrintAgentQueue::class)->online($printer))->toBeTrue();

    $printer->update(['print_via' => Printer::PRINT_VIA_SERVER]);
    expect($this->withToken($data['t'])->getJson('/api/print-agent/jobs')->json('enabled'))->toBeFalse();
});

it('queues kitchen tickets and kitchen changes for an online agent printer and marks them done', function () {
    $printer = Printer::factory()->create([
        'name' => 'طابعة المطبخ',
        'ip_address' => null,
        'print_via' => Printer::PRINT_VIA_AGENT,
        'agent_printer' => 'Kitchen-XP80',
    ]);
    $code = app(PrintAgentQueue::class)->pair($printer);
    $printerToken = decodePairingCode($code)['t'];
    app(PrintAgentQueue::class)->seen($printer);

    $product = Product::factory()->create(['name' => 'شاورما لحم']);
    $product->printers()->attach($printer->id);
    $order = Order::factory()->create(['type' => 'dine_in']);

    $printService = app(PrintService::class);
    $printService->setPrintStrategy(fakePngPrintStrategy());

    $printService->printKitchenReceipt($order->id, [
        ['product_id' => $product->id, 'name' => 'شاورما لحم', 'quantity' => 2, 'notes' => 'بدون طماطم'],
    ]);

    $printService->printKitchenChangesReceipt($order->id, [
        ['product_id' => $product->id, 'name' => 'شاورما لحم', 'old_quantity' => 2, 'new_quantity' => 3, 'delta' => 1],
    ]);

    $kitchenJob = PrintAgentJob::query()->where('printer_id', $printer->id)->where('kind', 'kitchen')->sole();
    $changesJob = PrintAgentJob::query()->where('printer_id', $printer->id)->where('kind', 'kitchen_changes')->sole();

    expect($kitchenJob->terminal_id)->toBeNull()
        ->and($kitchenJob->printer)->toBe('Kitchen-XP80')
        ->and(base64_decode($kitchenJob->payload))->not->toBeEmpty()
        ->and($changesJob->terminal_id)->toBeNull()
        ->and($changesJob->printer)->toBe('Kitchen-XP80');

    // A terminal token or another printer token cannot claim or finish this printer's jobs.
    expect($this->withToken($this->token)->getJson('/api/print-agent/jobs')->json('jobs'))->toBe([]);
    $this->withToken($this->token)->postJson("/api/print-agent/jobs/{$kitchenJob->id}", ['ok' => true])->assertNotFound();

    $otherPrinter = Printer::factory()->create(['print_via' => Printer::PRINT_VIA_AGENT]);
    $otherToken = decodePairingCode(app(PrintAgentQueue::class)->pair($otherPrinter))['t'];
    expect($this->withToken($otherToken)->getJson('/api/print-agent/jobs')->json('jobs'))->toBe([]);
    $this->withToken($otherToken)->postJson("/api/print-agent/jobs/{$kitchenJob->id}", ['ok' => true])->assertNotFound();

    // The paired printer token claims both jobs with Arabic labels and marks them done.
    $jobs = $this->withToken($printerToken)->getJson('/api/print-agent/jobs')
        ->assertOk()
        ->json('jobs');

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]['kind'])->toBe('kitchen')
        ->and($jobs[0]['label'])->toBe('طلب مطبخ')
        ->and($jobs[0]['printer'])->toBe('Kitchen-XP80')
        ->and($jobs[1]['kind'])->toBe('kitchen_changes')
        ->and($jobs[1]['label'])->toBe('تغييرات طلب مطبخ');

    $this->withToken($printerToken)->postJson("/api/print-agent/jobs/{$kitchenJob->id}", ['ok' => true])->assertOk();
    $this->withToken($printerToken)->postJson("/api/print-agent/jobs/{$changesJob->id}", ['ok' => true])->assertOk();

    expect($kitchenJob->fresh()->status)->toBe(PrintAgentJob::DONE)
        ->and($changesJob->fresh()->status)->toBe(PrintAgentJob::DONE);
});

it('returns a 422 JSON error when printing to an offline kitchen printer via print-in-kitchen', function () {
    $printer = Printer::factory()->create([
        'name' => 'طابعة المطبخ الرئيسية',
        'ip_address' => null,
        'print_via' => Printer::PRINT_VIA_AGENT,
    ]);
    app(PrintAgentQueue::class)->pair($printer);

    $product = Product::factory()->create(['name' => 'برجر']);
    $product->printers()->attach($printer->id);
    $order = Order::factory()->create();

    $printService = app(PrintService::class);
    $printService->setPrintStrategy(fakePngPrintStrategy());
    $this->instance(PrintService::class, $printService);

    $this->postJson('/print-in-kitchen', [
        'orderId' => $order->id,
        'items' => [
            ['product_id' => $product->id, 'name' => 'برجر', 'quantity' => 1],
        ],
    ])
        ->assertStatus(422)
        ->assertJson(['ok' => false])
        ->assertJsonPath('message', fn (string $msg): bool => str_contains($msg, 'غير متصل'));
});

it('queues a test page for a kitchen printer via Turbo Label', function () {
    $printer = Printer::factory()->create([
        'name' => 'طابعة المطبخ',
        'ip_address' => null,
        'print_via' => Printer::PRINT_VIA_AGENT,
        'agent_printer' => 'Kitchen-XP80',
    ]);
    app(PrintAgentQueue::class)->pair($printer);
    app(PrintAgentQueue::class)->seen($printer);

    app(PrintService::class)->testThroughAgent($printer);

    $job = PrintAgentJob::query()->where('printer_id', $printer->id)->sole();
    expect($job->kind)->toBe('test')
        ->and($job->printer)->toBe('Kitchen-XP80')
        ->and(base64_decode($job->payload))->toContain('Turbo Label');
});
