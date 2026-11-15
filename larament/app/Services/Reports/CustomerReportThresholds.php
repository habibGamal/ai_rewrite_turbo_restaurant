<?php

namespace App\Services\Reports;

use App\Services\SettingsService;

/**
 * Editable thresholds for the customers performance report.
 * Stored as one JSON setting and edited from the report page.
 */
final class CustomerReportThresholds
{
    public const SETTING_KEY = 'customer_report_thresholds';

    public const DEFAULTS = [
        'vip_min_sales' => 5000,
        'vip_min_orders' => 20,
        'loyal_min_sales' => 2000,
        'loyal_min_orders' => 10,
        'regular_min_orders' => 5,
        'churn_gap_multiplier' => 2,
        'churn_min_days' => 14,
    ];

    public const SEGMENT_VIP = 'VIP';

    public const SEGMENT_LOYAL = 'مخلص';

    public const SEGMENT_REGULAR = 'عادي';

    public const SEGMENT_OCCASIONAL = 'عرضي';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, float> */
    public function all(): array
    {
        $stored = json_decode((string) $this->settings->get(self::SETTING_KEY, '{}'), true);
        $stored = is_array($stored) ? $stored : [];

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            $values[$key] = is_numeric($value) && $value >= 0 ? (float) $value : (float) $default;
        }

        return $values;
    }

    /** @param  array<string, mixed>  $values */
    public function save(array $values): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $values[$key] ?? $default;
            $clean[$key] = is_numeric($value) && $value >= 0 ? $value + 0 : $default;
        }

        $this->settings->set(self::SETTING_KEY, $clean);
    }

    /** @return array<string, string> segment => Filament color */
    public static function segmentColors(): array
    {
        return [
            self::SEGMENT_VIP => 'warning',
            self::SEGMENT_LOYAL => 'success',
            self::SEGMENT_REGULAR => 'info',
            self::SEGMENT_OCCASIONAL => 'gray',
        ];
    }

    public function describeSegments(): string
    {
        $t = $this->all();

        return sprintf(
            'VIP: %s+ ج.م و %s+ طلب · مخلص: %s+ ج.م و %s+ طلب · عادي: %s+ طلبات · عرضي: أقل من ذلك (خلال الفترة المختارة)',
            number_format($t['vip_min_sales']),
            number_format($t['vip_min_orders']),
            number_format($t['loyal_min_sales']),
            number_format($t['loyal_min_orders']),
            number_format($t['regular_min_orders']),
        );
    }
}
