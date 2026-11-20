<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\FeatureService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Report order tables show only the money taken inside the report's shifts. Without this, an
 * order sold in shift 1 and collected in shift 2 looks fully paid in shift 1's table while
 * shift 1's cash card shows only the first part.
 */
trait ScopedOrderPayments
{
    /**
     * Eager load for `payments` limited to the report's shifts.
     *
     * @return array<string, \Closure>
     */
    protected function scopedPaymentsEagerLoad(QueryBuilder $scope): array
    {
        return [
            'payments' => fn (Builder|Relation $query) => $query->whereIn('shift_id', clone $scope),
        ];
    }

    protected static function paidInScope(Order $record): float
    {
        return round((float) $record->payments->sum('amount'), 2);
    }

    protected static function uncollectedInScope(Order $record): float
    {
        if ($record->status !== OrderStatus::COMPLETED) {
            return 0.0;
        }

        return max(0.0, round((float) $record->total - self::paidInScope($record), 2));
    }

    /**
     * @return list<TextColumn>
     */
    protected function scopedPaymentColumns(string $scopeLabel): array
    {
        return [
            TextColumn::make('payment_status')
                ->label('حالة الدفع الآن')
                ->badge()
                ->tooltip('حالة الطلب اليوم، شاملة أي تحصيل تم في شفت لاحق')
                ->toggleable()
                ->visible(fn (): bool => self::deferredPaymentOn()),

            TextColumn::make('paid_in_scope')
                ->label('المدفوع في '.$scopeLabel)
                ->state(fn (Order $record): float => self::paidInScope($record))
                ->numeric(decimalPlaces: 2)
                ->suffix(' جنيه')
                ->color('primary')
                ->alignCenter(),

            TextColumn::make('uncollected_in_scope')
                ->label('آجل غير محصل في '.$scopeLabel)
                ->state(fn (Order $record): float => self::uncollectedInScope($record))
                ->numeric(decimalPlaces: 2)
                ->suffix(' جنيه')
                ->color(fn (Order $record): string => self::uncollectedInScope($record) > 0 ? 'danger' : 'gray')
                ->weight(fn (Order $record): ?string => self::uncollectedInScope($record) > 0 ? 'bold' : null)
                ->alignCenter()
                ->visible(fn (): bool => self::deferredPaymentOn()),
        ];
    }

    protected function scopedPaymentMethodFilter(QueryBuilder $scope): SelectFilter
    {
        return SelectFilter::make('payment_method')
            ->label('طريقة الدفع')
            ->options(PaymentMethod::class)
            ->query(fn (Builder $query, array $data): Builder => $query->when(
                $data['value'] ?? null,
                fn (Builder $query, $value): Builder => $query->whereHas(
                    'payments',
                    fn (Builder $payments) => $payments->where('method', $value)->whereIn('shift_id', clone $scope)
                )
            ));
    }

    protected function hasCreditFilter(QueryBuilder $scope): TernaryFilter
    {
        $condition = function (Builder $query, string $operator) use ($scope): Builder {
            $sub = clone $scope;

            return $query->where('orders.status', OrderStatus::COMPLETED->value)->whereRaw(
                'orders.total - COALESCE((SELECT SUM(scoped_payments.amount) FROM payments AS scoped_payments'
                .' WHERE scoped_payments.order_id = orders.id AND scoped_payments.shift_id IN ('.$sub->toSql().')), 0) '.$operator.' 0.005',
                $sub->getBindings()
            );
        };

        return TernaryFilter::make('has_credit')
            ->label('آجل غير محصل')
            ->queries(
                true: fn (Builder $query) => $condition($query, '>'),
                false: fn (Builder $query) => $condition($query, '<='),
                blank: fn (Builder $query) => $query,
            )
            ->visible(fn (): bool => self::deferredPaymentOn());
    }

    protected function paymentStatusFilter(): SelectFilter
    {
        return SelectFilter::make('payment_status')
            ->label('حالة الدفع الآن')
            ->options(PaymentStatus::class)
            ->visible(fn (): bool => self::deferredPaymentOn());
    }

    /**
     * Payment status and the uncollected column only tell a story when
     * orders may close unpaid, so they follow the deferred payment switch.
     */
    protected static function deferredPaymentOn(): bool
    {
        return app(FeatureService::class)->deferredPaymentEnabled();
    }
}
