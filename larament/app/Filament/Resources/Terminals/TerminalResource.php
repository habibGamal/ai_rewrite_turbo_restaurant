<?php

namespace App\Filament\Resources\Terminals;

use App\Enums\SettingKey;
use App\Filament\Resources\Terminals\Pages\CreateTerminal;
use App\Filament\Resources\Terminals\Pages\EditTerminal;
use App\Filament\Resources\Terminals\Pages\ListTerminals;
use App\Filament\Traits\BelongsToModule;
use App\Models\Terminal;
use App\Modules\ModuleScreens;
use App\PrintAgent\PrintAgentQueue;
use App\Services\PrintService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * The shop's cash drawers («الأدراج»). Hidden (404) while multiple
 * terminals are off, see ModuleScreens. The vendor sets how many may be
 * active (max_terminals); drawers are never deleted, only stopped.
 */
class TerminalResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Terminal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return ModuleScreens::allows(static::class) && parent::shouldRegisterNavigation();
    }

    public static function getModelLabel(): string
    {
        return 'درج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الأدراج';
    }

    public static function maxActive(): int
    {
        return max(1, (int) setting(SettingKey::MAX_TERMINALS));
    }

    /**
     * Refuse saving a drawer as active when the vendor's limit is reached.
     *
     * @throws ValidationException
     */
    public static function assertWithinLimit(bool $active, ?Terminal $record = null): void
    {
        if (! $active || ($record !== null && $record->is_active)) {
            return;
        }

        $count = Terminal::query()->active()->count();

        if ($count >= self::maxActive()) {
            throw ValidationException::withMessages([
                'data.is_active' => 'وصلت إلى الحد الأقصى للأدراج المفعلة ('.self::maxActive().'). تواصل مع الدعم الفني لزيادته.',
            ]);
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم الدرج')
                    ->placeholder('كاشير ١')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Section::make('طباعة الفاتورة ودرج النقود')
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('print_via')
                            ->label('الطباعة عن طريق')
                            ->options([
                                Terminal::PRINT_VIA_SERVER => 'الخادم (IP أو طابعة مشتركة)',
                                Terminal::PRINT_VIA_AGENT => 'برنامج Turbo Label على جهاز الدرج',
                            ])
                            ->helperText('إذا كان النظام على خادم بعيد عن المتجر (VPS)، فاختر برنامج Turbo Label ليطبع على الطابعة المتصلة بجهاز الكاشير.')
                            ->default(Terminal::PRINT_VIA_SERVER)
                            ->inline()
                            ->live()
                            ->required(),
                        TextInput::make('receipt_printer')
                            ->label('طابعة الفاتورة')
                            ->helperText('عنوان IP أو اسم الطابعة المتصل بها درج النقود. إذا تُركت فارغة تُستخدم طابعة الكاشير المحددة في الإعدادات.')
                            ->visible(fn (Get $get): bool => $get('print_via') !== Terminal::PRINT_VIA_AGENT)
                            ->maxLength(255),
                        Select::make('agent_printer')
                            ->label('طابعة الفاتورة على جهاز الدرج')
                            ->helperText('من الطابعات التي عثر عليها البرنامج في الجهاز. إذا تُركت فارغة تُستخدم الطابعة المختارة في البرنامج نفسه.')
                            ->options(fn (?Terminal $record): array => collect($record?->agentPrinters() ?? [])
                                ->when(filled($record?->agent_printer), fn ($c) => $c->push($record->agent_printer))
                                ->unique()
                                ->mapWithKeys(fn (string $name): array => [$name => $name])
                                ->all())
                            ->placeholder('طابعة البرنامج')
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('print_via') === Terminal::PRINT_VIA_AGENT),
                        Text::make(fn (?Terminal $record): string => self::agentStatus($record))
                            ->visible(fn (Get $get): bool => $get('print_via') === Terminal::PRINT_VIA_AGENT),
                    ]),
                TextInput::make('sort')
                    ->label('الترتيب')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('مفعل')
                    ->helperText('لا يمكن فتح الدرج الموقوف، ويبقى ظاهراً في التقارير السابقة.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('اسم الدرج')->searchable()->sortable(),
                TextColumn::make('receipt_printer')
                    ->label('طابعة الفاتورة')
                    ->state(fn (Terminal $record): ?string => $record->printsViaAgent()
                        ? 'Turbo Label'.($record->agentPrinter() !== null ? ' · '.$record->agentPrinter() : '')
                        : $record->receiptPrinter())
                    ->description(fn (Terminal $record): ?string => $record->printsViaAgent() ? self::agentStatus($record) : null)
                    ->placeholder('طابعة الإعدادات'),
                IconColumn::make('is_active')->label('مفعل')->boolean(),
                TextColumn::make('devices_count')->label('الأجهزة المربوطة')->counts('devices'),
                TextColumn::make('sort')->label('الترتيب')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('pairAgent')
                    ->label(fn (Terminal $record): string => $record->agentPaired() ? 'رمز ربط جديد للبرنامج' : 'ربط برنامج الطباعة')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->visible(fn (Terminal $record): bool => $record->printsViaAgent() && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('ربط برنامج Turbo Label بالدرج')
                    ->modalDescription(fn (Terminal $record): string => $record->agentPaired()
                        ? 'سيُلغي الرمز الجديد ربط البرنامج الحالي، ويجب ربطه من جديد.'
                        : 'افتح هذه الصفحة من جهاز الدرج نفسه، وبعد ظهور الرمز اضغط «ربط البرنامج» أو انسخ الرمز في نافذة البرنامج.')
                    ->modalSubmitActionLabel('إنشاء رمز الربط')
                    ->action(function (Terminal $record): void {
                        $code = app(PrintAgentQueue::class)->pair($record);

                        Notification::make()
                            ->success()
                            ->persistent()
                            ->title('رمز ربط الدرج «'.$record->name.'»')
                            ->body(new HtmlString(
                                '<p>على جهاز الدرج، اضغط «ربط البرنامج»، أو افتح Turbo Label والصق هذا الرمز في «ربط بدرج»:</p>'
                                .'<code dir="ltr" style="display:block;user-select:all;word-break:break-all;font-size:11px;margin-top:6px">'
                                .e($code).'</code>'
                            ))
                            ->actions([
                                Action::make('openAgent')
                                    ->label('ربط البرنامج')
                                    ->button()
                                    ->url('turbo-label://pair?code='.urlencode($code)),
                            ])
                            ->send();
                    }),
                Action::make('testAgent')
                    ->label('تجربة الطباعة')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (Terminal $record): bool => $record->printsViaAgent() && $record->agentPaired())
                    ->action(function (Terminal $record): void {
                        try {
                            app(PrintService::class)->testThroughAgent($record);
                            Notification::make()->success()->title('طبع البرنامج صفحة التجربة')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('فشلت التجربة')->body($e->getMessage())->send();
                        }
                    }),
                Action::make('unpairAgent')
                    ->label('إلغاء ربط البرنامج')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Terminal $record): bool => $record->agentPaired() && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->action(function (Terminal $record): void {
                        app(PrintAgentQueue::class)->unpair($record);
                        Notification::make()->success()->title('تم إلغاء ربط البرنامج')->send();
                    }),
                Action::make('unbindDevices')
                    ->label('فك ربط الأجهزة')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('ستطلب الأجهزة المربوطة بهذا الدرج الربط من جديد عند أول فتح لنقطة البيع.')
                    ->visible(fn (Terminal $record): bool => $record->devices()->exists() && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Terminal $record): void {
                        $record->devices()->delete();
                        Notification::make()->success()->title('تم فك ربط الأجهزة')->send();
                    }),
            ]);
    }

    public static function agentStatus(?Terminal $record): string
    {
        if ($record === null || ! $record->agentPaired()) {
            return 'البرنامج غير مربوط. احفظ الدرج ثم اضغط «ربط برنامج الطباعة» من قائمة الأدراج.';
        }

        $info = $record->agent_info ?? [];
        $machine = filled($info['machine'] ?? null) ? ' على '.$info['machine'] : '';

        if (app(PrintAgentQueue::class)->online($record)) {
            return 'البرنامج متصل'.$machine.'.';
        }

        return $record->agent_last_seen_at !== null
            ? 'البرنامج غير متصل'.$machine.'، آخر اتصال '.$record->agent_last_seen_at->diffForHumans().'.'
            : 'البرنامج مربوط لكنه لم يتصل بعد.';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTerminals::route('/'),
            'create' => CreateTerminal::route('/create'),
            'edit' => EditTerminal::route('/{record}/edit'),
        ];
    }
}
