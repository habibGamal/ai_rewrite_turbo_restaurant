<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Terminal;
use App\Models\User;
use App\Modules\Module;
use App\Services\ChannelPerformanceReportService;
use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_business_context')]
#[Title('Business context')]
#[Description(<<<'TEXT'
Call this first in every conversation. Returns the shop's name and type, today's date and time in the shop's timezone, whether a shift is open, the enabled modules and features, the sales channels (order type codes to pass as order_types), active sales points, the date range that has data, and who you are acting as.
TEXT)]
#[IsReadOnly]
class GetBusinessContextTool extends BusinessTool
{
    private readonly SettingsService $settings;

    private readonly FeatureService $features;

    private readonly ShiftService $shifts;

    private readonly ChannelPerformanceReportService $channels;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?SettingsService $settings = null, ?FeatureService $features = null, ?ShiftService $shifts = null, ?ChannelPerformanceReportService $channels = null)
    {
        $this->settings = $settings ?? app(SettingsService::class);
        $this->features = $features ?? app(FeatureService::class);
        $this->shifts = $shifts ?? app(ShiftService::class);
        $this->channels = $channels ?? app(ChannelPerformanceReportService::class);
    }

    protected function reportPages(): array
    {
        return self::ANY_REPORT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): array
    {
        $now = CarbonImmutable::now();
        $shift = $this->shifts->getCurrentShift();
        $user = Auth::user();

        $dates = Order::query()
            ->where('status', OrderStatus::COMPLETED)
            ->toBase()
            ->selectRaw('MIN(created_at) AS first_at, MAX(created_at) AS last_at, COUNT(*) AS orders')
            ->first();

        $multiTerminal = $this->features->multiTerminalEnabled();

        return $this->envelope(null, array_filter([
            'shop' => [
                'name' => $this->settings->getRestaurantName(),
                'business_type' => $this->features->businessType()->value,
                'business_type_label' => $this->features->businessType()->label(),
            ],
            'now' => [
                'date' => $now->toDateString(),
                'time' => $now->format('H:i'),
                'weekday' => $now->format('l'),
                'timezone' => (string) config('app.timezone'),
            ],
            'open_shift' => $shift === null ? null : [
                'id' => $shift->id,
                'business_date' => $shift->business_date?->toDateString() ?? CarbonImmutable::parse($shift->start_at)->toDateString(),
                'started_at' => CarbonImmutable::parse($shift->start_at)->format('Y-m-d H:i'),
                'opened_by' => $shift->user?->getAttribute('name'),
            ],
            'modules' => array_map(fn (Module $module) => ['code' => $module->value, 'label' => $module->label()], $this->features->enabledModules()),
            'features' => array_values(array_map(
                fn (OptionalFeature $feature) => ['code' => $feature->name, 'label' => $feature->label()],
                array_filter(OptionalFeature::cases(), fn (OptionalFeature $feature) => $this->features->isEnabled($feature)),
            )),
            'order_types' => array_map(
                fn (string $code, array $channel) => ['code' => $code, 'label' => $channel['label']],
                array_keys($this->channels->channels()),
                $this->channels->channels(),
            ),
            'sales_points' => $multiTerminal
                ? Terminal::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name'])->map(fn (Terminal $t) => ['id' => $t->id, 'name' => $t->name])->all()
                : null,
            'data_range' => [
                'first_order' => $dates?->first_at ? CarbonImmutable::parse($dates->first_at)->toDateString() : null,
                'last_order' => $dates?->last_at ? CarbonImmutable::parse($dates->last_at)->toDateString() : null,
                'completed_orders' => (int) ($dates->orders ?? 0),
            ],
            'acting_as' => [
                'name' => $user?->isVendor() ? User::VENDOR_DISPLAY_NAME : $user?->name,
                'roles' => $user?->roles->pluck('name')->all() ?? [],
            ],
        ], fn ($value) => $value !== null), [
            'order_types' => 'Sales channels; pass their code in order_types.',
            'data_range' => 'Dates of the first and last completed order.',
        ]);
    }
}
