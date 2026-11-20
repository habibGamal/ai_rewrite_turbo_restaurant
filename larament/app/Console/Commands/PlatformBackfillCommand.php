<?php

namespace App\Console\Commands;

use App\Sync\BackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class PlatformBackfillCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:backfill {--from= : Start date (YYYY-MM-DD)} {--to= : End date (YYYY-MM-DD)} {--catalog : Backfill catalog documents} {--recover : Recover incomplete initial backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue catalog and range documents into sync outbox';

    /**
     * Execute the console command.
     */
    public function handle(BackfillService $backfill): int
    {
        try {
            if ($this->option('recover')) {
                $recovered = $backfill->recoverInitialBackfillIfNeeded();
                $this->info($recovered ? 'Initial backfill recovered successfully.' : 'No initial backfill needed recovery or recovery locked.');

                return self::SUCCESS;
            }

            $doCatalog = (bool) $this->option('catalog');
            $fromOption = $this->option('from');
            $toOption = $this->option('to');

            if ($fromOption !== null && ! $this->isValidDate((string) $fromOption)) {
                $this->error("Invalid --from date: {$fromOption}. Expected format: YYYY-MM-DD.");

                return self::FAILURE;
            }

            if ($toOption !== null && ! $this->isValidDate((string) $toOption)) {
                $this->error("Invalid --to date: {$toOption}. Expected format: YYYY-MM-DD.");

                return self::FAILURE;
            }

            if ($fromOption === null && $toOption !== null) {
                $this->error('The --from option is required when --to is specified.');

                return self::FAILURE;
            }

            // If neither was specified, default to catalog + 90 days range
            if (! $doCatalog && ! $fromOption && ! $toOption) {
                $doCatalog = true;
                $from = Carbon::today()->subDays(90)->startOfDay();
                $to = Carbon::today()->startOfDay();
            } else {
                $from = $fromOption ? Carbon::createFromFormat('Y-m-d', (string) $fromOption)->startOfDay() : null;
                $to = $toOption ? Carbon::createFromFormat('Y-m-d', (string) $toOption)->startOfDay() : Carbon::today()->startOfDay();
            }

            if ($from !== null && $from->gt($to)) {
                $this->error("Invalid date range: --from ({$from->toDateString()}) cannot be after --to ({$to->toDateString()}).");

                return self::FAILURE;
            }

            $total = 0;

            if ($doCatalog) {
                $catalogCount = $backfill->queueCatalog();
                $this->info("Queued {$catalogCount} catalog documents.");
                $total += $catalogCount;
            }

            if ($from !== null) {
                $rangeCount = $backfill->queueRange($from, $to);
                $this->info("Queued {$rangeCount} range documents ({$from->toDateString()} to {$to->toDateString()}).");
                $total += $rangeCount;
            }

            $this->info("Backfill finished. Total documents queued: {$total}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backfill failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Validate YYYY-MM-DD date string.
     */
    protected function isValidDate(?string $value): bool
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = explode('-', $value);

        return checkdate((int) $month, (int) $day, (int) $year);
    }
}
