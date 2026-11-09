<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Traits\ViewerAccess;
use App\Models\Customer;
use App\Models\Order;
use App\Orders\OrderTypeCatalog;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class CompaniesAccounts extends Page implements HasTable
{
    use InteractsWithTable;
    use ViewerAccess;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    protected static ?string $navigationLabel = 'حسابات الشركات';

    protected static ?string $title = 'حسابات الشركات';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.companies-accounts';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->customersQuery())
            ->heading('حسابات الشركات')
            ->columns([
                TextColumn::make('name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable(),
                TextColumn::make('order_count')
                    ->label('عدد الطلبات')
                    ->state(fn (Customer $record): int => $record->orders->count()),
                TextColumn::make('orders_total')
                    ->label('الإجمالي')
                    ->state(fn (Customer $record): float => $this->ordersTotal($record))
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2)),
                TextColumn::make('orders_paid')
                    ->label('المدفوع')
                    ->state(fn (Customer $record): float => $this->ordersPaid($record))
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2)),
                TextColumn::make('outstanding')
                    ->label('المتبقي')
                    ->state(fn (Customer $record): float => round($this->ordersTotal($record) - $this->ordersPaid($record), 2))
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2)),
                TextColumn::make('order_list')
                    ->label('الطلبات')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->state(fn (Customer $record): array => $this->orderLines($record)),
            ])
            ->recordUrl(null)
            ->emptyStateHeading('لا توجد أرصدة شركات')
            ->paginated([10, 25, 50]);
    }

    /**
     * @return Builder<Customer>
     */
    private function customersQuery(): Builder
    {
        $codes = app(OrderTypeCatalog::class)->codesWhere(
            fn ($behavior): bool => $behavior->allowsPartialPayment()
        );

        return Customer::query()
            ->whereHas('orders', fn (Builder $query) => $this->constrainPartialOrders($query, $codes))
            ->with([
                'orders' => function (Relation $query) use ($codes): void {
                    $this->constrainPartialOrders($query, $codes);
                    $query->with('payments')->orderByDesc('id');
                },
            ])
            ->orderBy('name');
    }

    /**
     * @param  list<string>  $codes
     */
    private function constrainPartialOrders(Builder|Relation $query, array $codes): void
    {
        $query->where('status', OrderStatus::COMPLETED)
            ->where('payment_status', PaymentStatus::PARTIAL_PAID)
            ->whereIn('type', $codes);
    }

    private function ordersTotal(Customer $customer): float
    {
        return round((float) $customer->orders->sum('total'), 2);
    }

    private function ordersPaid(Customer $customer): float
    {
        $paid = 0.0;

        foreach ($customer->orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }

            $paid += (float) $order->payments->sum('amount');
        }

        return round($paid, 2);
    }

    /**
     * @return list<string>
     */
    private function orderLines(Customer $customer): array
    {
        $lines = [];

        foreach ($customer->orders as $order) {
            if (! $order instanceof Order) {
                continue;
            }

            $paid = round((float) $order->payments->sum('amount'), 2);
            $remaining = round((float) $order->total - $paid, 2);
            $lines[] = '#'.$order->order_number.' — متبقي '.number_format($remaining, 2);
        }

        return $lines;
    }
}
