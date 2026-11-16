<?php

namespace App\Filament\Resources\Terminals;

use App\Enums\SettingKey;
use App\Filament\Resources\Terminals\Pages\CreateTerminal;
use App\Filament\Resources\Terminals\Pages\EditTerminal;
use App\Filament\Resources\Terminals\Pages\ListTerminals;
use App\Filament\Traits\BelongsToModule;
use App\Models\Terminal;
use App\Modules\ModuleScreens;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                'data.is_active' => 'وصلت لأقصى عدد أدراج مفعلة ('.self::maxActive().'). كلم الدعم الفني لزيادته.',
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
                TextInput::make('receipt_printer')
                    ->label('طابعة الفاتورة')
                    ->helperText('عنوان IP أو اسم الطابعة المتوصل بيها درج الفلوس. لو فاضية بيستخدم طابعة الكاشير اللي في الإعدادات.')
                    ->maxLength(255),
                TextInput::make('sort')
                    ->label('الترتيب')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_active')
                    ->label('مفعل')
                    ->helperText('الدرج الموقوف مايقدرش يتفتح، ويفضل في التقارير القديمة.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('اسم الدرج')->searchable()->sortable(),
                TextColumn::make('receipt_printer')->label('طابعة الفاتورة')->placeholder('طابعة الإعدادات'),
                IconColumn::make('is_active')->label('مفعل')->boolean(),
                TextColumn::make('devices_count')->label('الأجهزة المربوطة')->counts('devices'),
                TextColumn::make('sort')->label('الترتيب')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('unbindDevices')
                    ->label('فك ربط الأجهزة')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('الأجهزة المربوطة بالدرج ده هتطلب الربط تاني من أول مرة تفتح فيها نقطة البيع.')
                    ->visible(fn (Terminal $record): bool => $record->devices()->exists() && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Terminal $record): void {
                        $record->devices()->delete();
                        Notification::make()->success()->title('تم فك ربط الأجهزة')->send();
                    }),
            ]);
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
