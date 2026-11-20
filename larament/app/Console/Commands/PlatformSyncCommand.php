<?php

namespace App\Console\Commands;

use App\Sync\BackfillService;
use App\Sync\BatchSender;
use Illuminate\Console\Command;
use Throwable;

class PlatformSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:sync {--max-seconds=50 : Maximum execution time in seconds} {--no-recovery : Skip initial backfill recovery}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync pending outbox records to central platform';

    /**
     * Execute the console command.
     */
    public function handle(BatchSender $sender, BackfillService $backfill): int
    {
        $maxSeconds = (int) ($this->option('max-seconds') ?: 50);

        try {
            if (! $this->option('no-recovery')) {
                $backfill->recoverInitialBackfillIfNeeded();
            }

            $result = $sender->run($maxSeconds);

            $this->info(sprintf(
                'Batches: %d | Sent: %d | Rejected: %d | Failed: %d%s',
                $result->batches,
                $result->sent,
                $result->rejected,
                $result->failed,
                $result->error ? ' | Error: '.$result->error : ''
            ));
        } catch (Throwable $e) {
            $this->info('Batches: 0 | Sent: 0 | Rejected: 0 | Failed: 0 | Error: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
