<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\Models\Terminal;
use App\PrintAgent\PrintAgentJob;
use App\PrintAgent\PrintAgentQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Turbo Label app on a drawer's computer talks to. It sends the
 * drawer's token as a Bearer token; no user session is involved.
 */
class PrintAgentController extends Controller
{
    public function __construct(private readonly PrintAgentQueue $queue) {}

    /**
     * The app says hello on start and every minute: who it is and which
     * printers the computer has, so the admin can pick one.
     */
    public function hello(Request $request): JsonResponse
    {
        $terminal = $this->terminal($request);

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:50'],
            'machine' => ['nullable', 'string', 'max:100'],
            'printers' => ['nullable', 'array', 'max:100'],
            'printers.*' => ['string', 'max:255'],
        ]);

        $this->queue->seen($terminal, [
            'version' => $data['version'] ?? null,
            'machine' => $data['machine'] ?? null,
            'printers' => array_values($data['printers'] ?? []),
        ]);

        return response()->json($this->describe($terminal));
    }

    public function jobs(Request $request): JsonResponse
    {
        $terminal = $this->terminal($request);
        $this->queue->seen($terminal);

        $jobs = $terminal->printsViaAgent() ? $this->queue->claim($terminal) : collect();

        return response()->json([
            ...$this->describe($terminal),
            'jobs' => $jobs->map(fn (PrintAgentJob $job): array => [
                'id' => $job->id,
                'kind' => $job->kind,
                'label' => $job->kindLabel(),
                'printer' => $job->printer,
                'data' => $job->payload,
            ])->values(),
        ]);
    }

    public function finish(Request $request, int $job): JsonResponse
    {
        $terminal = $this->terminal($request);

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = PrintAgentJob::query()
            ->where('terminal_id', $terminal->id)
            ->findOrFail($job);

        $this->queue->finish($record, (bool) $data['ok'], $data['error'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function terminal(Request $request): Terminal
    {
        $terminal = $this->queue->terminalForToken($request->bearerToken());

        abort_if($terminal === null, 401, 'رمز الربط غير صحيح أو أُلغي. اربط البرنامج بمنفذ البيع من جديد.');

        return $terminal;
    }

    /** @return array<string, mixed> */
    private function describe(Terminal $terminal): array
    {
        return [
            'shop' => (string) setting(SettingKey::RESTAURANT_NAME),
            'terminal' => $terminal->name,
            'enabled' => $terminal->printsViaAgent() && $terminal->is_active,
            'printer' => $terminal->agentPrinter(),
        ];
    }
}
