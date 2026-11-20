<?php

namespace App\Filament\Widgets\CustomFees;

use App\Orders\OrderTypeCatalog;
use App\Services\CustomFeesReportService;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class CustomFeesLinesTable extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'تفاصيل الرسوم';

    public function table(Table $table): Table
    {
        $scope = app(ShiftsReportService::class)->shiftIdsQueryFromFilters($this->pageFilters);
        $catalog = app(OrderTypeCatalog::class);

        return $table
            ->query(app(CustomFeesReportService::class)->linesQuery($scope)->with(['order.shift', 'user'])->withSum('returnLines as refunded', 'refund_amount'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('اسم الرسوم')->searchable(),
                TextColumn::make('amount')->label('القيمة')->money('EGP')->sortable()
                    ->summarize(Sum::make()->label('الإجمالي')->money('EGP')),
                TextColumn::make('refunded')->label('المرتجع')->money('EGP')->placeholder('-')->color('danger')->visible(fn (): bool => app(FeatureService::class)->returnsEnabled()),
                TextColumn::make('order.order_number')->label('رقم الطلب'),
                TextColumn::make('order.type')->label('نوع الطلب')
                    ->formatStateUsing(fn (?string $state): string => $state ? $catalog->display($state)->label : '-'),
                TextColumn::make('order.shift.business_date')->label('يوم العمل')->date('d/m/Y'),
                TextColumn::make('order.shift_id')->label('الوردية')->prefix('#'),
                TextColumn::make('user.name')->label('أضافها'),
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i'),
            ])
            ->filters([
                SelectFilter::make('order_type')
                    ->label('نوع الطلب')
                    ->options(fn (): array => $catalog->all()->mapWithKeys(fn ($row) => [$row->code => $catalog->display($row->code)->label])->all())
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $type) => $query->whereHas('order', fn (Builder $order) => $order->where('type', $type)),
                    )),
            ])
            ->paginated([10, 25, 50])
            ->recordAction(null)
            ->recordUrl(null);
    }
}
