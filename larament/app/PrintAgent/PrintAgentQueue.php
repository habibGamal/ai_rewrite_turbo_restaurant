<?php

namespace App\PrintAgent;

use App\Models\Printer;
use App\Models\Terminal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The queue between the POS and the Turbo Label app on each paired computer
 * (sales points and kitchen printers), plus pairing the app with a target.
 */
class PrintAgentQueue
{
    /** A job nobody picked up in this time is dropped, not printed late. */
    public const EXPIRE_SECONDS = 120;

    /** The app polls every second or two; quieter than this = offline. */
    public const ONLINE_SECONDS = 20;

    public const TOKEN_PREFIX = 'TL1.';

    public function push(PrintAgentTarget $target, string $kind, string $bytes): PrintAgentJob
    {
        return PrintAgentJob::query()->create([
            $target->agentTargetColumn() => $target->agentTargetId(),
            'kind' => $kind,
            'printer' => $target->agentPrinter(),
            'payload' => base64_encode($bytes),
            'status' => PrintAgentJob::PENDING,
        ]);
    }

    /**
     * Wait up to config('print_agent.wait_seconds') for the app to print
     * the job. A failure, or an app that isn't connected, is thrown so the
     * cashier sees it; a connected app that is just slow is fine.
     *
     * @throws PrintAgentException
     */
    public function await(PrintAgentJob $job): void
    {
        $deadline = microtime(true) + (float) config('print_agent.wait_seconds', 6);

        while (true) {
            $job->refresh();

            if ($job->status === PrintAgentJob::DONE) {
                return;
            }

            if ($job->status === PrintAgentJob::FAILED) {
                throw new PrintAgentException('تعذرت الطباعة في برنامج Turbo Label: '.$job->error);
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(200_000);
        }

        $target = $job->target();

        if ($target === null || ! $target->agentPaired()) {
            throw new PrintAgentException(sprintf(
                'برنامج Turbo Label غير مربوط بـ«%s».',
                $target?->agentTargetName() ?? '',
            ));
        }

        if (! $this->online($target)) {
            throw new PrintAgentException(sprintf(
                'برنامج Turbo Label على جهاز «%s» غير متصل. شغّله، وإذا عمل خلال دقيقتين فستُطبع.',
                $target->agentTargetName(),
            ));
        }
    }

    public function online(PrintAgentTarget $target): bool
    {
        $lastSeen = $target->agentLastSeenAt();

        return $lastSeen !== null
            && $lastSeen->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }

    /**
     * Hand the app its waiting jobs (oldest first) and mark them sent.
     * Expired jobs are failed instead.
     *
     * @return Collection<int, PrintAgentJob>
     */
    public function claim(PrintAgentTarget $target): Collection
    {
        $column = $target->agentTargetColumn();
        $id = $target->agentTargetId();

        PrintAgentJob::query()
            ->where($column, $id)
            ->where('status', PrintAgentJob::PENDING)
            ->where('created_at', '<', now()->subSeconds(self::EXPIRE_SECONDS))
            ->update([
                'status' => PrintAgentJob::FAILED,
                'error' => 'انتهت المهلة قبل أن يستلمها البرنامج',
                'finished_at' => now(),
            ]);

        return DB::transaction(function () use ($column, $id): Collection {
            $jobs = PrintAgentJob::query()
                ->where($column, $id)
                ->where('status', PrintAgentJob::PENDING)
                ->orderBy('id')
                ->limit(20)
                ->lockForUpdate()
                ->get();

            if ($jobs->isNotEmpty()) {
                PrintAgentJob::query()->whereKey($jobs->modelKeys())->update([
                    'status' => PrintAgentJob::SENT,
                    'sent_at' => now(),
                ]);
            }

            return $jobs;
        });
    }

    public function finish(PrintAgentJob $job, bool $ok, ?string $error): void
    {
        if (in_array($job->status, [PrintAgentJob::DONE, PrintAgentJob::FAILED], true)) {
            return;
        }

        $job->update([
            'status' => $ok ? PrintAgentJob::DONE : PrintAgentJob::FAILED,
            'error' => $ok ? null : Str::limit((string) $error, 500),
            'finished_at' => now(),
        ]);
    }

    /**
     * A new token for the target (the old one stops working). Returns the
     * pairing code the app accepts: TL1. + base64url JSON {s, t, n, k}.
     */
    public function pair(PrintAgentTarget $target): string
    {
        $token = Str::random(48);

        $target->updateAgentState([
            'agent_token_hash' => hash('sha256', $token),
            'agent_paired_at' => now(),
            'agent_last_seen_at' => null,
            'agent_info' => null,
        ]);

        return self::TOKEN_PREFIX.rtrim(strtr(base64_encode((string) json_encode([
            's' => rtrim(url('/'), '/'),
            't' => $token,
            'n' => $target->agentTargetName(),
            'k' => $target->agentTargetKey(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public function unpair(PrintAgentTarget $target): void
    {
        $target->updateAgentState([
            'agent_token_hash' => null,
            'agent_paired_at' => null,
            'agent_last_seen_at' => null,
            'agent_info' => null,
        ]);
    }

    public function targetForToken(?string $token): ?PrintAgentTarget
    {
        if ($token === null || strlen($token) < 20) {
            return null;
        }

        $hash = hash('sha256', $token);

        return Terminal::query()->where('agent_token_hash', $hash)->first()
            ?? Printer::query()->where('agent_token_hash', $hash)->first();
    }

    public function terminalForToken(?string $token): ?Terminal
    {
        if ($token === null || strlen($token) < 20) {
            return null;
        }

        return Terminal::query()->where('agent_token_hash', hash('sha256', $token))->first();
    }

    /**
     * Note that the app was heard from, and what it said about itself.
     *
     * @param  array<string, mixed>|null  $info
     */
    public function seen(PrintAgentTarget $target, ?array $info = null): void
    {
        $attributes = ['agent_last_seen_at' => now()];

        if ($info !== null) {
            $attributes['agent_info'] = $info;
        }

        $target->updateAgentState($attributes);
    }
}
