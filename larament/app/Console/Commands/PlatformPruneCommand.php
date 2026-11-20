<?php

namespace App\Console\Commands;

use App\Models\SyncBatch;
use App\Models\SyncOutbox;
use Illuminate\Console\Command;

class PlatformPruneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune sent outbox rows and batches older than 30 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(30);

        $prunedOutbox = SyncOutbox::whereNotNull('sent_at')
            ->where('sent_at', '<', $cutoff)
            ->delete();

        $prunedBatches = SyncBatch::where('status', 'sent')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('sync_outbox')
                    ->whereColumn('sync_outbox.batch_id', 'sync_batches.id');
            })
            ->delete();

        $this->info("Pruned {$prunedOutbox} outbox rows and {$prunedBatches} sync batches older than 30 days.");

        return self::SUCCESS;
    }
}
