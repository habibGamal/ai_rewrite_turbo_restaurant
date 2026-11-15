<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\AuditAction;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «سجل التعديلات»: read-only list of audit rows. Rows are append-only, so
 * there is no create, edit or delete here (not even for admin).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return 'تعديل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل التعديلات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Arabic name of an audited model, for the table and filter.
     */
    public static function typeLabel(?string $type): string
    {
        return match (class_basename((string) $type)) {
            'Expense' => 'مصروف',
            'Shift' => 'وردية',
            'Order' => 'طلب',
            'OrderReturn' => 'مرتجع',
            'Revenue' => 'إيراد',
            'OrderFee' => 'رسوم طلب',
            'OrderEquipment' => 'معدات طلب',
            default => class_basename((string) $type),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('الوقت')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('action')
                    ->label('العملية')
                    ->badge(),
                TextColumn::make('auditable_type')
                    ->label('النوع')
                    ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
                TextColumn::make('auditable_id')
                    ->label('الرقم')
                    ->prefix('#'),
                TextColumn::make('shift_id')
                    ->label('الوردية')
                    ->prefix('#')
                    ->placeholder('-'),
                TextColumn::make('business_date')
                    ->label('يوم العمل')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->placeholder('-'),
                TextColumn::make('reason')
                    ->label('السبب')
                    ->limit(40)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('shift_id')
                    ->label('الوردية')
                    ->options(fn () => Shift::query()->latest('id')->limit(200)->pluck('id', 'id')->mapWithKeys(fn ($id) => [$id => "#{$id}"]))
                    ->searchable(),
                SelectFilter::make('user_id')
                    ->label('المستخدم')
                    ->options(fn () => User::query()->pluck('name', 'id')),
                SelectFilter::make('action')
                    ->label('العملية')
                    ->options(AuditAction::class),
                SelectFilter::make('auditable_type')
                    ->label('النوع')
                    ->options(fn () => AuditLog::query()->distinct()->pluck('auditable_type')
                        ->mapWithKeys(fn ($type) => [$type => self::typeLabel($type)])),
                Filter::make('business_date')
                    ->schema([
                        DatePicker::make('from')->label('من يوم'),
                        DatePicker::make('until')->label('إلى يوم'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('business_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('business_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التعديل')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('action')->label('العملية')->badge(),
                        TextEntry::make('auditable_type')->label('النوع')
                            ->formatStateUsing(fn (?string $state): string => self::typeLabel($state)),
                        TextEntry::make('auditable_id')->label('الرقم')->prefix('#'),
                        TextEntry::make('shift_id')->label('الوردية')->prefix('#')->placeholder('-'),
                        TextEntry::make('business_date')->label('يوم العمل')->date('d/m/Y')->placeholder('-'),
                        TextEntry::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i'),
                        TextEntry::make('user.name')->label('المستخدم')->placeholder('-'),
                        TextEntry::make('context')->label('السياق')->placeholder('-'),
                        TextEntry::make('reason')->label('السبب')->placeholder('-')->columnSpanFull(),
                    ]),
                Section::make('القيم')
                    ->columns(2)
                    ->schema([
                        KeyValueEntry::make('old_values')->label('قبل')->placeholder('-'),
                        KeyValueEntry::make('new_values')->label('بعد')->placeholder('-'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
