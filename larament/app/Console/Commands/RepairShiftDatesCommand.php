<?php

namespace App\Console\Commands;

use App\Models\PlatformConnection;
use App\Models\Shift;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use App\Sync\Payload;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RepairShiftDatesCommand extends Command
{
    protected $signature = 'platform:repair-shift-dates
        {--apply : Apply the previewed repair and queue corrected documents}
        {--from-created-at : Confirm historical created_at is a trustworthy shift opening timestamp}';

    protected $description = 'Preview or repair shifts with overwritten historical opening timestamps';

    public function handle(): int
    {
        $count = $this->candidates()->count();
        $this->info("Found {$count} shifts with overwritten opening dates and usable historical created_at.");
        $this->table(['ID', 'Start currently', 'End', 'Proposed start', 'Current business date'],
            $this->candidates()->limit(20)->get()->map(fn (Shift $shift): array => [
                $shift->id, $shift->getRawOriginal('start_at'), $shift->getRawOriginal('end_at'),
                $shift->getRawOriginal('created_at'), $shift->getRawOriginal('business_date'),
            ])->all());

        if (! $this->option('apply')) {
            $this->comment('Preview only. Verify created_at against the original shift history before using --apply --from-created-at.');

            return self::SUCCESS;
        }

        if (! $this->option('from-created-at')) {
            $this->error('Applying requires --from-created-at after verifying the historical timestamps.');

            return self::FAILURE;
        }

        try {
            $this->ensureStartCannotAutoUpdate();
            $repaired = DB::transaction(function (): int {
                $shifts = $this->candidates()->lockForUpdate()->get();
                if ($shifts->isEmpty()) {
                    return 0;
                }

                $backup = 'repairs/shift-dates/'.Str::uuid().'.json';
                $originals = $shifts->map(fn (Shift $shift): array => $shift->getRawOriginal())->all();
                if (! Storage::disk('local')->put($backup, json_encode($originals, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
                    throw new RuntimeException('Could not save the original shift records; no dates were changed.');
                }
                $this->info("Original shift records saved to local storage: {$backup}");

                $summaryDates = [];
                foreach ($shifts as $shift) {
                    $created = $shift->created_at;
                    if ($created === null) {
                        continue;
                    }
                    $summaryDates[$created->toDateString()] = true;
                    $oldDate = Payload::date($shift->getAttribute('business_date'));
                    if ($oldDate !== null) {
                        $summaryDates[$oldDate] = true;
                    }
                    DB::table('shifts')->where('id', $shift->id)->update([
                        'start_at' => $created->format('Y-m-d H:i:s'),
                        'business_date' => $created->toDateString(),
                        'updated_at' => now(),
                    ]);
                    Outbox::record(DocumentType::Shift, (string) $shift->getAttribute('uuid'));
                    foreach (['orders' => DocumentType::Order, 'order_returns' => DocumentType::OrderReturn, 'expenses' => DocumentType::Expense] as $table => $type) {
                        DB::table($table)->where('shift_id', $shift->id)->whereNotNull('uuid')
                            ->orderBy('id')->chunkById(500, function ($rows) use ($type): void {
                                foreach ($rows as $row) {
                                    Outbox::record($type, $row->uuid);
                                }
                            });
                    }
                }

                if (PlatformConnection::active() !== null) {
                    foreach (array_keys($summaryDates) as $date) {
                        Outbox::record(DocumentType::DaySummary, DateDocument::uuid(DocumentType::DaySummary, $date), key: $date);
                    }
                }

                return $shifts->count();
            });
            $this->info("Repaired {$repaired} shifts. Corrected documents queued when paired; run platform:sync.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return Builder<Shift> */
    private function candidates(): Builder
    {
        return Shift::query()->whereNotNull('created_at')->where(function (Builder $query): void {
            $query->where(function (Builder $closed): void {
                $closed->whereNotNull('end_at')->whereRaw('DATE(start_at) > DATE(end_at)')
                    ->whereColumn('created_at', '<=', 'end_at');
            })->orWhere(function (Builder $open): void {
                $open->whereNull('end_at')->whereRaw('DATE(created_at) < DATE(start_at)')
                    ->whereHas('orders', function (Builder $orders): void {
                        $orders->whereColumn('orders.created_at', '>=', 'shifts.created_at')
                            ->whereRaw('DATE(orders.created_at) < DATE(shifts.start_at)');
                    });
            });
        })->orderBy('id');
    }

    private function ensureStartCannotAutoUpdate(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $column = DB::selectOne("SHOW COLUMNS FROM shifts WHERE Field = 'start_at'");
        if ($column !== null && str_contains(strtolower((string) $column->Extra), 'on update')) {
            throw new RuntimeException('Run php artisan migrate --force first to remove automatic start_at updates.');
        }
    }
}
