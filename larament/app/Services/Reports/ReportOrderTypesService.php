<?php

namespace App\Services\Reports;

use App\Orders\CashierTabLayout;
use App\Orders\OrderTypeCatalog;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ReportOrderTypesService
{
    public function __construct(
        private readonly OrderTypeCatalog $catalog,
        private readonly CashierTabLayout $layout,
    ) {}

    /** @return list<array{code: string, label: string, color: string}> */
    public function forQuery(Builder $query): array
    {
        $sold = (clone $query)->reorder()->distinct()->pluck('orders.type')->all();
        $catalog = $this->catalog->all()->keyBy('code');
        $result = [];
        foreach ($this->layout->orderedTypeCodes() as $code) {
            $row = $catalog->get($code);
            if ($row === null || (! $row->is_active && ! in_array($code, $sold, true))) {
                continue;
            }
            self::validateCode($code);
            $result[] = ['code' => $code, 'label' => $row->label, 'color' => $row->color];
        }

        return $result;
    }

    public static function validateCode(string $code): void
    {
        if (! preg_match('/\A[a-z][a-z0-9_]*\z/', $code)) {
            throw new InvalidArgumentException("Invalid order type code [{$code}].");
        }
    }

    public function label(string $code): string
    {
        $row = $this->catalog->all()->firstWhere('code', $code);

        return $row === null ? $code : $row->label;
    }
}
