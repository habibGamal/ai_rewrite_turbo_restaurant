<?php

namespace App\Filament\Pages;

use App\Models\PlatformConnection;
use App\Models\SyncOutbox;
use App\Sync\BackfillService;
use App\Sync\BatchSender;
use App\Sync\Exceptions\PairingFailed;
use App\Sync\PairingService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Throwable;

class PlatformConnectionPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_settings') ?? false;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected string $view = 'filament.pages.platform-connection-page';

    protected static ?string $navigationLabel = 'ربط المنصة';

    protected static ?string $title = 'ربط المنصة';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 101;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pair')
                ->label('ربط')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->modalHeading('ربط المنصة')
                ->modalSubmitActionLabel('ربط')
                ->hidden(fn (): bool => PlatformConnection::active() !== null)
                ->schema([
                    TextInput::make('url')
                        ->label('رابط المنصة')
                        ->url()
                        ->required()
                        ->placeholder('https://platform.example.com'),
                    TextInput::make('code')
                        ->label('كود الربط')
                        ->required()
                        ->length(8)
                        ->placeholder('12345678'),
                ])
                ->action(function (array $data): void {
                    try {
                        app(PairingService::class)->pair((string) $data['url'], (string) $data['code']);

                        Notification::make()
                            ->title('تم ربط المنصة بنجاح')
                            ->body('تم ربط نقطة البيع بالمنصة المركزية بنجاح')
                            ->icon('heroicon-o-check-circle')
                            ->iconColor('success')
                            ->send();
                    } catch (PairingFailed $e) {
                        Notification::make()
                            ->title('فشل ربط المنصة')
                            ->body($e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('فشل ربط المنصة')
                            ->body('حدث خطأ أثناء محاولة ربط المنصة: '.$e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    }
                }),

            Action::make('unpair')
                ->label('فك الربط')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('فك ربط المنصة')
                ->modalDescription('هل أنت متأكد من فك ربط المنصة؟ سيتم إيقاف المزامنة حتى إعادة الربط.')
                ->modalSubmitActionLabel('تأكيد فك الربط')
                ->visible(fn (): bool => PlatformConnection::active() !== null)
                ->action(function (): void {
                    try {
                        app(PairingService::class)->unpair();

                        Notification::make()
                            ->title('تم فك ربط المنصة بنجاح')
                            ->icon('heroicon-o-check-circle')
                            ->iconColor('success')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('خطأ في فك الربط')
                            ->body($e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    }
                }),

            Action::make('syncNow')
                ->label('مزامنة الآن')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (): bool => PlatformConnection::active() !== null)
                ->action(function (): void {
                    try {
                        $result = app(BatchSender::class)->run(20);

                        $body = "تم إرسال {$result->sent}، تم رفض {$result->rejected}، فشل {$result->failed}";
                        if ($result->error) {
                            $body .= " (خطأ: {$result->error})";
                        }

                        Notification::make()
                            ->title('اكتملت المزامنة')
                            ->body($body)
                            ->icon($result->failed > 0 || $result->rejected > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                            ->iconColor($result->failed > 0 || $result->rejected > 0 ? 'warning' : 'success')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('خطأ أثناء المزامنة')
                            ->body($e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    }
                }),

            Action::make('retryFailed')
                ->label('إعادة محاولة الفاشل')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->action(function (): void {
                    try {
                        $updated = SyncOutbox::whereNotNull('failed_at')
                            ->update([
                                'failed_at' => null,
                                'attempts' => 0,
                                'next_attempt_at' => null,
                            ]);

                        Notification::make()
                            ->title('تمت إعادة جدولة السجلات الفاشلة')
                            ->body("تمت إعادة جدولة {$updated} سجل للمحاولة مجدداً")
                            ->icon('heroicon-o-check-circle')
                            ->iconColor('success')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('خطأ أثناء إعادة المحاولة')
                            ->body($e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    }
                }),

            Action::make('resendRange')
                ->label('إعادة إرسال فترة')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->visible(fn (): bool => PlatformConnection::active() !== null)
                ->modalHeading('إعادة إرسال فترة زمنية')
                ->modalSubmitActionLabel('بدء الجدولة')
                ->schema([
                    DatePicker::make('from')
                        ->label('من تاريخ')
                        ->required(),
                    DatePicker::make('to')
                        ->label('إلى تاريخ')
                        ->required()
                        ->default(now()->toDateString()),
                ])
                ->action(function (array $data): void {
                    try {
                        $from = Carbon::parse((string) $data['from']);
                        $to = Carbon::parse((string) $data['to']);
                        $count = app(BackfillService::class)->queueRange($from, $to);

                        Notification::make()
                            ->title('تمت جدولة إعادة الإرسال')
                            ->body("تمت جدولة {$count} سجل لإعادة الإرسال")
                            ->icon('heroicon-o-check-circle')
                            ->iconColor('success')
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('خطأ أثناء جدولة الفترة')
                            ->body($e->getMessage())
                            ->icon('heroicon-o-x-circle')
                            ->iconColor('danger')
                            ->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                SyncOutbox::query()
                    ->whereNotNull('failed_at')
                    ->orderBy('occurred_at', 'desc')
            )
            ->heading('السجلات الفاشلة')
            ->description('قائمة بالوثائق والمستندات التي تعذر إرسالها إلى المنصة المركزية')
            ->columns([
                TextColumn::make('doc_type')
                    ->label('نوع المستند')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('doc_uuid')
                    ->label('المعرف الفريد')
                    ->copyable()
                    ->searchable()
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('attempts')
                    ->label('المحاولات')
                    ->badge()
                    ->color('danger')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('last_error')
                    ->label('آخر خطأ')
                    ->wrap()
                    ->limit(100),

                TextColumn::make('occurred_at')
                    ->label('وقت الحدوث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->emptyStateHeading('لا توجد سجلات فاشلة')
            ->emptyStateDescription('جميع السجلات تم إرسالها بنجاح أو لا توجد أخطاء حالياً.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->striped()
            ->paginated([10, 25, 50]);
    }

    public function getConnection(): ?PlatformConnection
    {
        return PlatformConnection::active() ?? PlatformConnection::latest('id')->first();
    }

    public function getStatusLabel(): string
    {
        $connection = $this->getConnection();
        if (! $connection) {
            return 'غير مربوط';
        }

        if ($connection->status === 'revoked') {
            return 'مرفوض';
        }

        if ($connection->last_success_at !== null && Carbon::parse($connection->last_success_at)->lt(now()->subMinutes(15))) {
            return 'متأخر';
        }

        return 'متصل';
    }

    public function getStatusColor(): string
    {
        return match ($this->getStatusLabel()) {
            'متصل' => 'success',
            'متأخر' => 'warning',
            'مرفوض' => 'danger',
            default => 'gray',
        };
    }

    public function getPlatformUrl(): string
    {
        $connection = $this->getConnection();

        return $connection ? $connection->url : '—';
    }

    public function getBranchUuid(): string
    {
        $connection = $this->getConnection();

        return $connection ? $connection->branch_uuid : '—';
    }

    public function getLastSuccess(): string
    {
        $connection = $this->getConnection();

        return $connection && $connection->last_success_at
            ? Carbon::parse($connection->last_success_at)->diffForHumans()
            : '—';
    }

    public function getLastHeartbeat(): string
    {
        $connection = $this->getConnection();

        return $connection && $connection->last_heartbeat_at
            ? Carbon::parse($connection->last_heartbeat_at)->diffForHumans()
            : '—';
    }

    public function getPendingCount(): int
    {
        return SyncOutbox::whereNull('sent_at')->whereNull('failed_at')->count();
    }

    public function getOldestPendingAge(): string
    {
        $oldest = SyncOutbox::whereNull('sent_at')
            ->whereNull('failed_at')
            ->orderBy('occurred_at', 'asc')
            ->value('occurred_at');

        return $oldest ? Carbon::parse($oldest)->diffForHumans() : '—';
    }

    public function getFailedCount(): int
    {
        return SyncOutbox::whereNotNull('failed_at')->count();
    }

    public function getLastError(): string
    {
        $connection = $this->getConnection();
        if ($connection && $connection->last_error) {
            return $connection->last_error;
        }

        $failedError = SyncOutbox::whereNotNull('failed_at')->latest('id')->value('last_error');

        return is_string($failedError) && $failedError !== '' ? $failedError : '—';
    }
}
