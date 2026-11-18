<?php

namespace App\Http\Controllers;

use App\Enums\SettingKey;
use App\PrintAgent\PrintAgentJob;
use App\PrintAgent\PrintAgentQueue;
use App\PrintAgent\PrintAgentTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the Turbo Label app on a shop computer talks to. It sends the
 * paired target's token as a Bearer token; no user session is involved.
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
        $target = $this->target($request);

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:50'],
            'machine' => ['nullable', 'string', 'max:100'],
            'printers' => ['nullable', 'array', 'max:100'],
            'printers.*' => ['string', 'max:255'],
        ]);

        $this->queue->seen($target, [
            'version' => $data['version'] ?? null,
            'machine' => $data['machine'] ?? null,
            'printers' => array_values($data['printers'] ?? []),
        ]);

        return response()->json($this->describe($target));
    }

    public function jobs(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $this->queue->seen($target);

        $jobs = $target->agentEnabled() ? $this->queue->claim($target) : collect();

        return response()->json([
            ...$this->describe($target),
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
        $target = $this->target($request);

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = PrintAgentJob::query()
            ->where($target->agentTargetColumn(), $target->agentTargetId())
            ->findOrFail($job);

        $this->queue->finish($record, (bool) $data['ok'], $data['error'] ?? null);

        return response()->json(['ok' => true]);
    }

    private function target(Request $request): PrintAgentTarget
    {
        $target = $this->queue->targetForToken($request->bearerToken());

        abort_if($target === null, 401, 'رمز الربط غير صحيح أو أُلغي. اربط البرنامج من جديد.');

        return $target;
    }

    /** @return array<string, mixed> */
    private function describe(PrintAgentTarget $target): array
    {
        return [
            'shop' => (string) setting(SettingKey::RESTAURANT_NAME),
            'terminal' => $target->agentTargetName(),
            'enabled' => $target->agentEnabled(),
            'printer' => $target->agentPrinter(),
        ];
    }
}
