<?php

namespace App\Orders;

use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\OrderTypeRow;
use App\Models\Shift;
use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;

final class CashierTabLayout
{
    private const OPEN_STATUSES = ['processing', 'pending', 'out_for_delivery'];

    private const SYSTEM_TABS = [
        'receivables' => [
            'label' => 'تحصيل الآجل',
            'color' => 'warning',
            'icon' => 'heroicon-o-banknotes',
        ],
        'expenses' => [
            'label' => 'مصاريف',
            'color' => 'danger',
            'icon' => 'heroicon-o-receipt-percent',
        ],
        'cancelled' => [
            'label' => 'ملغي',
            'color' => 'danger',
            'icon' => 'heroicon-o-x-circle',
        ],
        'completed' => [
            'label' => 'منتهي',
            'color' => 'success',
            'icon' => 'heroicon-o-check-circle',
        ],
    ];

    public function __construct(
        private readonly OrderTypeCatalog $catalog,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return list<array{
     *     key: string,
     *     kind: 'type'|'system',
     *     code: ?string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     visible: bool,
     *     forcedVisible: bool
     * }>
     */
    public function resolve(?Shift $shift): array
    {
        $typeRows = $this->catalog->all()->keyBy('code');
        $tabs = [];
        $seen = [];

        foreach ($this->storedEntries() as $entry) {
            if (isset($seen[$entry['key']])) {
                continue;
            }

            $seen[$entry['key']] = true;
            $tab = $this->tabForEntry($entry['key'], $entry['kind'], $entry['name'], $entry['visible'], $typeRows, $shift);

            if ($tab !== null) {
                $tabs[] = $tab;
            }
        }

        foreach ($typeRows as $row) {
            if (! $row->is_active) {
                continue;
            }

            $key = 'type:'.$row->code;
            if (isset($seen[$key])) {
                continue;
            }

            $tabs[] = $this->typeEntry($row, true, $shift);
        }

        return $tabs;
    }

    /**
     * @return list<string>
     */
    public function visibleTypeCodes(): array
    {
        $codes = [];

        foreach ($this->resolve(null) as $tab) {
            if ($tab['kind'] === 'type' && $tab['visible'] && $tab['code'] !== null) {
                $codes[] = $tab['code'];
            }
        }

        return $codes;
    }

    /**
     * @return list<array{
     *     key: string,
     *     kind: 'type'|'system',
     *     code: ?string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     visible: bool,
     *     forcedVisible: bool
     * }>
     */
    public function entriesForEditor(): array
    {
        return $this->resolve(null);
    }

    /**
     * @param  array<int, mixed>  $entries
     */
    public function save(array $entries): void
    {
        $submitted = $this->normalizeEntries($entries);

        $hasVisible = false;
        foreach ($submitted as $entry) {
            if ($entry['visible']) {
                $hasVisible = true;

                break;
            }
        }

        if (! $hasVisible) {
            throw ValidationException::withMessages([
                SettingKey::CASHIER_TABS->value => 'يجب إظهار تبويب واحد على الأقل',
            ]);
        }

        $typeRows = $this->catalog->all()->keyBy('code');
        $stored = $this->storedEntries();
        $inactive = [];

        foreach ($stored as $entry) {
            if ($entry['kind'] !== 'type') {
                continue;
            }

            $row = $typeRows->get($entry['name']);
            if ($row instanceof OrderTypeRow && ! $row->is_active) {
                $inactive[] = $entry;
            }
        }

        $result = $submitted;
        $keys = array_column($result, 'key');

        foreach ($inactive as $entry) {
            if (in_array($entry['key'], $keys, true)) {
                continue;
            }

            $position = $this->inactivePosition($stored, $entry['key'], $keys);

            array_splice($result, $position, 0, [$entry]);
            array_splice($keys, $position, 0, [$entry['key']]);
        }

        $payload = [];
        foreach ($result as $entry) {
            $payload[] = [
                'key' => $entry['key'],
                'visible' => $entry['visible'],
            ];
        }

        $this->settings->set(SettingKey::CASHIER_TABS->value, $payload);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, OrderTypeRow>  $typeRows
     * @return array{
     *     key: string,
     *     kind: 'type'|'system',
     *     code: ?string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     visible: bool,
     *     forcedVisible: bool
     * }|null
     */
    private function tabForEntry(
        string $key,
        string $kind,
        string $name,
        bool $visible,
        \Illuminate\Support\Collection $typeRows,
        ?Shift $shift,
    ): ?array {
        if ($kind === 'system') {
            return $this->systemEntry($name, $visible);
        }

        $row = $typeRows->get($name);

        if (! $row instanceof OrderTypeRow || ! $row->is_active) {
            return null;
        }

        return $this->typeEntry($row, $visible, $shift);
    }

    /**
     * @return array{
     *     key: string,
     *     kind: 'type',
     *     code: string,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     visible: bool,
     *     forcedVisible: bool
     * }
     */
    private function typeEntry(OrderTypeRow $row, bool $visible, ?Shift $shift): array
    {
        $forcedVisible = false;

        if (! $visible && $shift !== null) {
            $forcedVisible = Order::query()
                ->where('type', $row->code)
                ->where('shift_id', $shift->id)
                ->whereIn('status', self::OPEN_STATUSES)
                ->exists();
        }

        return [
            'key' => 'type:'.$row->code,
            'kind' => 'type',
            'code' => $row->code,
            'label' => $row->label,
            'color' => $row->color,
            'icon' => $row->icon,
            'visible' => $visible,
            'forcedVisible' => $forcedVisible,
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     kind: 'system',
     *     code: null,
     *     label: string,
     *     color: string,
     *     icon: string,
     *     visible: bool,
     *     forcedVisible: bool
     * }
     */
    private function systemEntry(string $name, bool $visible): array
    {
        $system = self::SYSTEM_TABS[$name];

        return [
            'key' => 'system:'.$name,
            'kind' => 'system',
            'code' => null,
            'label' => $system['label'],
            'color' => $system['color'],
            'icon' => $system['icon'],
            'visible' => $visible,
            'forcedVisible' => false,
        ];
    }

    /**
     * @return list<array{key: string, kind: 'type'|'system', name: string, visible: bool}>
     */
    private function storedEntries(): array
    {
        $raw = $this->settings->get(
            SettingKey::CASHIER_TABS->value,
            SettingKey::CASHIER_TABS->defaultValue(),
        );

        $entries = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($entries)) {
            return [];
        }

        return $this->normalizeEntries($entries);
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return list<array{key: string, kind: 'type'|'system', name: string, visible: bool}>
     */
    private function normalizeEntries(array $entries): array
    {
        $typeRows = $this->catalog->all()->keyBy('code');
        $normalized = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['key']) || ! is_string($entry['key'])) {
                continue;
            }

            $key = $entry['key'];

            if (isset($normalized[$key])) {
                continue;
            }

            $parsed = $this->parseKey($key);

            if ($parsed === null) {
                continue;
            }

            if ($parsed['kind'] === 'type' && ! $typeRows->has($parsed['name'])) {
                continue;
            }

            $normalized[$key] = [
                'key' => $key,
                'kind' => $parsed['kind'],
                'name' => $parsed['name'],
                'visible' => filter_var($entry['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return array_values($normalized);
    }

    /**
     * @return array{kind: 'type'|'system', name: string}|null
     */
    private function parseKey(string $key): ?array
    {
        if (str_starts_with($key, 'type:')) {
            $code = substr($key, 5);

            return $code === '' ? null : ['kind' => 'type', 'name' => $code];
        }

        if (str_starts_with($key, 'system:')) {
            $name = substr($key, 7);

            return array_key_exists($name, self::SYSTEM_TABS)
                ? ['kind' => 'system', 'name' => $name]
                : null;
        }

        return null;
    }

    /**
     * @param  list<array{key: string, kind: 'type'|'system', name: string, visible: bool}>  $stored
     * @param  array<int, string>  $keys
     */
    private function inactivePosition(array $stored, string $key, array $keys): int
    {
        $index = array_search($key, array_column($stored, 'key'), true);

        if ($index === false) {
            return count($keys);
        }

        foreach (array_slice($stored, $index + 1) as $next) {
            $position = array_search($next['key'], $keys, true);

            if ($position !== false) {
                return $position;
            }
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            $position = array_search($stored[$i]['key'], $keys, true);

            if ($position !== false) {
                return $position + 1;
            }
        }

        return count($keys);
    }
}
