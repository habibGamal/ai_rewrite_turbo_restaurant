<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\KitchenItemStatus;
use App\Models\KitchenTicketItem;
use App\Modules\Module;
use App\Services\FeatureService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class KitchenItemsRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(FeatureService::class)->moduleEnabled(Module::RESTAURANT)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    protected static string $relationship = 'kitchenItems';

    protected static ?string $title = 'بنود المطبخ وأوقات التحضير';

    protected static ?string $modelLabel = 'بند مطبخ';

    protected static ?string $pluralModelLabel = 'بنود المطبخ';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['station', 'round']))
            ->columns([
                TextColumn::make('product_name')
                    ->label('اسم الصنف')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('station.name')
                    ->label('المحطة')
                    ->badge()
                    ->placeholder('عام')
                    ->color(fn ($state): string => $state ? 'primary' : 'gray'),

                TextColumn::make('round.number')
                    ->label('الجولة')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => "جولة {$state}")
                    ->color('info'),

                TextColumn::make('quantity')
                    ->label('الكمية')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => (string) round((float) $state, 3)),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('kitchen_ticket_items.status', $direction))
                    ->badge(),

                TextColumn::make('target_minutes')
                    ->label('الوقت المستهدف')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state).' دقيقة')
                    ->color('gray'),

                TextColumn::make('sent_at')
                    ->label('وقت الإرسال')
                    ->sortable()
                    ->dateTime('H:i:s'),

                TextColumn::make('done_at')
                    ->label('وقت الانتهاء')
                    ->sortable()
                    ->dateTime('H:i:s')
                    ->placeholder('-'),

                TextColumn::make('prep_time')
                    ->label('مدة التحضير')
                    ->state(fn (KitchenTicketItem $record): string => $record->formattedPrepTime())
                    ->color('primary')
                    ->weight('semibold'),

                TextColumn::make('delay_status')
                    ->label('حالة التأخير')
                    ->state(fn (KitchenTicketItem $record): string => $record->delayLabel())
                    ->badge()
                    ->color(fn (KitchenTicketItem $record): string => match (true) {
                        $record->status === KitchenItemStatus::VOIDED => 'gray',
                        $record->isLate() => 'danger',
                        $record->status === KitchenItemStatus::DONE => 'success',
                        default => 'info',
                    })
                    ->tooltip(function (KitchenTicketItem $record): ?string {
                        if ($record->isLate()) {
                            $delayMin = (int) ceil($record->delaySeconds() / 60);

                            return "تجاوز الوقت المستهدف بمقدار {$delayMin} دقيقة";
                        }

                        if ($record->status === KitchenItemStatus::DONE) {
                            return 'تم التحضير ضمن الوقت المستهدف';
                        }

                        return null;
                    }),

                TextColumn::make('notes')
                    ->label('ملاحظات')
                    ->limit(30)
                    ->searchable()
                    ->placeholder('-')
                    ->tooltip(fn (KitchenTicketItem $record): ?string => $record->notes),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('حالة البند')
                    ->attribute('kitchen_ticket_items.status')
                    ->options(KitchenItemStatus::class),

                SelectFilter::make('kitchen_station_id')
                    ->label('المحطة')
                    ->attribute('kitchen_ticket_items.kitchen_station_id')
                    ->relationship('station', 'name'),

                Filter::make('late_only')
                    ->label('البنود المتأخرة فقط')
                    ->query(function (Builder $query): Builder {
                        if (DB::getDriverName() === 'sqlite') {
                            return $query->where('kitchen_ticket_items.status', '!=', KitchenItemStatus::VOIDED->value)
                                ->where('kitchen_ticket_items.target_minutes', '>', 0)
                                ->where(function (Builder $q): void {
                                    $q->where(function (Builder $sub): void {
                                        $sub->whereNotNull('kitchen_ticket_items.done_at')
                                            ->whereRaw("(strftime('%s', kitchen_ticket_items.done_at) - strftime('%s', kitchen_ticket_items.sent_at)) > (kitchen_ticket_items.target_minutes * 60)");
                                    })->orWhere(function (Builder $sub): void {
                                        $sub->where('kitchen_ticket_items.status', KitchenItemStatus::PENDING->value)
                                            ->whereNull('kitchen_ticket_items.done_at')
                                            ->whereRaw("(strftime('%s', 'now') - strftime('%s', kitchen_ticket_items.sent_at)) > (kitchen_ticket_items.target_minutes * 60)");
                                    });
                                });
                        }

                        return $query->where('kitchen_ticket_items.status', '!=', KitchenItemStatus::VOIDED->value)
                            ->where('kitchen_ticket_items.target_minutes', '>', 0)
                            ->where(function (Builder $q): void {
                                $q->where(function (Builder $sub): void {
                                    $sub->whereNotNull('kitchen_ticket_items.done_at')
                                        ->whereRaw('TIMESTAMPDIFF(SECOND, kitchen_ticket_items.sent_at, kitchen_ticket_items.done_at) > (kitchen_ticket_items.target_minutes * 60)');
                                })->orWhere(function (Builder $sub): void {
                                    $sub->where('kitchen_ticket_items.status', KitchenItemStatus::PENDING->value)
                                        ->whereNull('kitchen_ticket_items.done_at')
                                        ->whereRaw('TIMESTAMPDIFF(SECOND, kitchen_ticket_items.sent_at, NOW()) > (kitchen_ticket_items.target_minutes * 60)');
                                });
                            });
                    }),
            ])
            ->defaultSort('kitchen_ticket_items.id')
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
