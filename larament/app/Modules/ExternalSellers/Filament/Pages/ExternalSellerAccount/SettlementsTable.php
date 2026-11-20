<?php

namespace App\Modules\ExternalSellers\Filament\Pages\ExternalSellerAccount;

use App\Models\ExternalSellerSettlement;
use App\Services\FeatureService;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class SettlementsTable extends BaseWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public int|string|null $sellerId = null;

    public int|string|null $record = null;

    public static function canView(): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled()
            && (auth()->user()?->can('view_external_sellers') ?? false);
    }

    public function mount(int|string|null $sellerId = null, int|string|null $record = null): void
    {
        abort_unless(static::canView(), 403);

        $this->sellerId = $sellerId ?? $record;
        $this->record = $this->sellerId;
    }

    #[On('settlement-recorded')]
    public function refreshSettlements(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $sellerId = $this->sellerId ?? $this->record;

        return $table
            ->query(
                ExternalSellerSettlement::query()
                    ->when($sellerId, fn (Builder $q) => $q->where('external_seller_id', $sellerId))
                    ->with(['shift', 'user'])
                    ->latest('id')
            )
            ->heading('سجل المدفوعات والتسويات')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),

                TextColumn::make('shift_id')
                    ->label('الوردية')
                    ->formatStateUsing(fn ($state): string => $state ? "وردية #{$state}" : '-'),

                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2)),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->default('-'),

                TextColumn::make('note')
                    ->label('ملاحظة')
                    ->default('-'),
            ])
            ->filters([
                Filter::make('date')
                    ->label('الفترة الزمنية')
                    ->schema([
                        DatePicker::make('from')->label('من تاريخ'),
                        DatePicker::make('to')->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? $data['created_from'] ?? null;
                        $to = $data['to'] ?? $data['until'] ?? $data['created_until'] ?? null;

                        return $query
                            ->when($from, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', $d))
                            ->when($to, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', $d));
                    }),

                Filter::make('date_range')
                    ->hidden()
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                        DatePicker::make('created_from'),
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['from'] ?? $data['created_from'] ?? null;
                        $to = $data['to'] ?? $data['until'] ?? $data['created_until'] ?? null;

                        return $query
                            ->when($from, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '>=', $d))
                            ->when($to, fn (Builder $q, $d): Builder => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->deferFilters(false)
            ->emptyStateHeading('لا توجد تسويات مسجلة لهذا البائع');
    }
}
