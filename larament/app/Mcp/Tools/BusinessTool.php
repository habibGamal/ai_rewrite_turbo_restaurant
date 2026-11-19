<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Pages\Reports\ChannelPerformanceReport;
use App\Filament\Pages\Reports\CustomersPerformanceReport;
use App\Filament\Pages\Reports\DailyComparisonReport;
use App\Filament\Pages\Reports\PeakHoursPerformanceReport;
use App\Filament\Pages\Reports\ProductsSalesPerformanceReport;
use App\Mcp\Support\Period;
use App\Mcp\Support\ToolCallLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

/**
 * Base for the business-data tools: read only, gated by the same report pages
 * the user can open in the app, and answering with one JSON envelope.
 *
 * A subclass names the report pages that unlock it, then builds its data in
 * run(). Invalid arguments become an error the model can repeat to the user.
 */
#[IsReadOnly]
abstract class BusinessTool extends Tool
{
    /** Report pages that unlock any business tool, for tools without their own report. */
    public const ANY_REPORT = [
        ChannelPerformanceReport::class,
        ProductsSalesPerformanceReport::class,
        DailyComparisonReport::class,
        PeakHoursPerformanceReport::class,
        CustomersPerformanceReport::class,
    ];

    public const MAX_LIMIT = 100;

    /**
     * Report page classes (with a static canAccess()); access to any of them
     * unlocks the tool.
     *
     * @return list<class-string>
     */
    abstract protected function reportPages(): array;

    /**
     * @return array<string, mixed> the envelope's data part
     */
    abstract protected function run(Request $request): array;

    /** Hidden from tools/list when the user cannot use it. */
    public function shouldRegister(): bool
    {
        return $this->allowed();
    }

    public function allowed(): bool
    {
        if (Auth::user() === null) {
            return false;
        }

        foreach ($this->reportPages() as $page) {
            if ($page::canAccess()) {
                return true;
            }
        }

        return false;
    }

    public function handle(Request $request): Response
    {
        $started = hrtime(true);
        $error = null;

        try {
            if (! $this->allowed()) {
                $error = 'You do not have permission to see this data. Ask the shop admin for access to the matching report.';

                return Response::error($error);
            }

            try {
                return Response::json($this->run($request));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();

                return Response::error($error);
            } catch (Throwable $e) {
                report($e);
                $error = "The {$this->name()} tool failed. Try a shorter period or simpler filters.";

                return Response::error($error);
            }
        } finally {
            ToolCallLog::record(
                $this->name(),
                $request->all(),
                $error === null,
                $error,
                (int) round((hrtime(true) - $started) / 1_000_000),
            );
        }
    }

    /**
     * Schema for period, from, to and (optionally) compare_to.
     *
     * @return array<string, mixed>
     */
    protected function periodSchema(JsonSchema $schema, bool $compare = true, string $default = 'this_month'): array
    {
        $fields = [
            'period' => $schema->string()->enum(Period::PRESETS)
                ->description("Named period in the shop's calendar. Default {$default}. Ignored when from/to are given."),
            'from' => $schema->string()->description('First day, YYYY-MM-DD. Use with to for a custom period (max '.Period::MAX_DAYS.' days).'),
            'to' => $schema->string()->description('Last day, YYYY-MM-DD.'),
        ];

        if ($compare) {
            $fields['compare_to'] = $schema->string()->enum(Period::COMPARES)
                ->description('What to compare with. Default previous_period (same length, just before).');
        }

        return $fields;
    }

    protected function period(Request $request, string $default = 'this_month', string $defaultCompare = 'previous_period'): Period
    {
        return Period::fromArguments($request->all(), $default, $defaultCompare);
    }

    protected function limit(Request $request, int $default = 10): int
    {
        $limit = (int) ($request->get('limit') ?? $default);

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * The shared answer shape: what period and filters the numbers cover,
     * the numbers, one line per metric, and caveats to mention.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $definitions
     * @param  list<string>  $caveats
     * @param  array<string, mixed>  $filters
     * @param  array{from: string, to: string}|null  $comparedWith
     * @return array<string, mixed>
     */
    protected function envelope(
        ?Period $period,
        array $data,
        array $definitions = [],
        array $caveats = [],
        array $filters = [],
        ?array $comparedWith = null,
    ): array {
        if ($period?->isOpen()) {
            $caveats[] = 'The period includes today, so its figures can still change.';
        }

        return array_filter([
            'period' => $period?->toArray(),
            'compared_with' => $comparedWith,
            'filters' => $filters === [] ? null : $filters,
            'currency' => 'EGP',
            'data' => $data,
            'definitions' => $definitions === [] ? null : $definitions,
            'caveats' => $caveats === [] ? null : array_values(array_unique($caveats)),
        ], fn ($value) => $value !== null);
    }

    /** Percentage change, null when there is nothing to compare with. */
    protected static function change(float|int $current, float|int|null $previous): ?float
    {
        if ($previous === null || $previous == 0) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /** Percentage share, 0 when the base is 0. */
    protected static function share(float|int $part, float|int $whole): float
    {
        return $whole != 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
