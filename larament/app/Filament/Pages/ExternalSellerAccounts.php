<?php

namespace App\Filament\Pages;

use App\Exceptions\OrderException;
use App\Models\ExternalSeller;
use App\Services\Orders\ExternalSellerSettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ExternalSellerAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المطعم';

    protected static ?string $navigationLabel = 'مستحقات البائعين الخارجيين';

    protected static ?string $title = 'مستحقات البائعين الخارجيين';

    protected string $view = 'filament.pages.external-seller-accounts';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_external_sellers') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->query(ExternalSeller::query()->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label('البائع')->searchable(),
                TextColumn::make('phone')->label('الهاتف'),
                TextColumn::make('payable')->label('المستحق')
                    ->state(fn (ExternalSeller $record): float => app(ExternalSellerSettlementService::class)->payable($record))
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2)),
            ])
            ->recordActions([
                Action::make('pay')->label('دفع المستحقات')
                    ->visible(fn (): bool => auth()->user()?->can('update_external_sellers') ?? false)
                    ->schema([
                        TextInput::make('amount')->label('المبلغ')->required()->numeric()->minValue(0.01),
                        Textarea::make('note')->label('ملاحظة'),
                    ])
                    ->action(function (ExternalSeller $record, array $data): void {
                        try {
                            app(ExternalSellerSettlementService::class)->pay($record->id, (float) $data['amount'], $data['note'] ?? null);
                            Notification::make()->success()->title('تم دفع المستحقات')->send();
                        } catch (OrderException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ]);
    }
}
