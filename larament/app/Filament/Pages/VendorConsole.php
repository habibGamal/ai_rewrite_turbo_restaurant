<?php

namespace App\Filament\Pages;

use App\Enums\OptionalFeature;
use App\Enums\SettingKey;
use App\Exceptions\BusinessTypeLockedException;
use App\Models\FeatureFlagAudit;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The vendor's ("god mode") control page: business type (fixed after the
 * first order), modules and optional features, with the change log. Only
 * the vendor account can open it; the customer's admin gets 403 and never
 * sees it in the menu.
 *
 * @property Schema $form
 */
class VendorConsole extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'لوحة الدعم الفني';

    protected static ?string $title = 'لوحة الدعم الفني';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 101;

    protected static ?string $slug = 'vendor-console';

    protected string $view = 'filament.pages.vendor-console';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('vendor.console') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $features = app(FeatureService::class);

        $state = [
            'business_type' => $features->businessType()->value,
            'modules' => array_map(fn (Module $module): string => $module->value, $features->enabledModules()),
        ];

        foreach (OptionalFeature::standalone() as $feature) {
            $state['features'][$feature->value] = $features->isEnabled($feature);
        }

        $state['max_terminals'] = (int) app(SettingsService::class)->get(SettingKey::MAX_TERMINALS->value, SettingKey::MAX_TERMINALS->defaultValue());

        $this->form->fill($state);
    }

    public function businessTypeLocked(): bool
    {
        return app(FeatureService::class)->businessTypeLocked();
    }

    public function form(Schema $schema): Schema
    {
        $locked = $this->businessTypeLocked();

        return $schema
            ->components([
                Section::make('نوع النشاط')
                    ->description($locked
                        ? 'تم تثبيت نوع النشاط لأن هذه النسخة سجلت طلبات بالفعل.'
                        : 'يُحدد مرة واحدة قبل أول طلب. اختيار نوع يضبط الوحدات على القيم الافتراضية لهذا النوع، ويمكن تعديلها قبل الحفظ.')
                    ->schema([
                        Select::make('business_type')
                            ->label('نوع النشاط')
                            ->options(BusinessType::options())
                            ->required()
                            ->native(false)
                            ->disabled($locked)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $type = BusinessType::tryFrom((string) $state);
                                if ($type !== null) {
                                    // External sellers is never a default; keep the current choice.
                                    $modules = array_map(fn (Module $module): string => $module->value, $type->defaultModules());
                                    if (in_array(Module::EXTERNAL_SELLERS->value, (array) $get('modules'), true)) {
                                        $modules[] = Module::EXTERNAL_SELLERS->value;
                                    }
                                    $set('modules', $modules);
                                }
                            }),
                    ]),

                Section::make('الوحدات')
                    ->description('الوحدات المفعلة لهذه النسخة. إيقاف وحدة يخفي شاشاتها مع الاحتفاظ بالسجلات.')
                    ->schema([
                        CheckboxList::make('modules')
                            ->hiddenLabel()
                            ->options(Module::options())
                            ->descriptions(array_combine(
                                array_column(Module::cases(), 'value'),
                                array_map(fn (Module $module): string => $module->description(), Module::cases()),
                            ))
                            ->columns(2),
                    ]),

                Section::make('المميزات')
                    ->description('تفعيل أو إيقاف المميزات الاختيارية. عند إيقاف ميزة يتم إخفاء شاشاتها مع الاحتفاظ بالسجلات التاريخية.')
                    ->schema([
                        Grid::make(2)->schema(array_map(
                            fn (OptionalFeature $feature): Toggle => Toggle::make('features.'.$feature->value)
                                ->label($feature->label())
                                ->helperText($feature->helperText())
                                ->inline(false),
                            OptionalFeature::standalone(),
                        )),
                        TextInput::make('max_terminals')
                            ->label(SettingKey::MAX_TERMINALS->label())
                            ->helperText(SettingKey::MAX_TERMINALS->helperText())
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('features.'.OptionalFeature::MULTI_TERMINAL->value)),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $service = app(FeatureService::class);
        $actorId = auth()->id();

        try {
            DB::transaction(function () use ($data, $service, $actorId): void {
                $type = BusinessType::tryFrom((string) ($data['business_type'] ?? ''));
                if ($type !== null && $type !== $service->businessType()) {
                    $service->setBusinessType($type, $actorId, applyDefaultModules: false);
                }

                $service->setModules($data['modules'] ?? [], $actorId);

                $featureStates = [];
                foreach (OptionalFeature::standalone() as $feature) {
                    $featureStates[$feature->value] = (bool) ($data['features'][$feature->value] ?? false);
                }
                $service->updateFeatures($featureStates, $actorId);

                if (isset($data['max_terminals'])) {
                    app(SettingsService::class)->set(SettingKey::MAX_TERMINALS->value, (string) (int) $data['max_terminals']);
                }
            });
        } catch (BusinessTypeLockedException $e) {
            $this->fillForm();
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            // Re-key errors onto the form's state paths.
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $key = (string) $key;
                if (str_starts_with($key, 'data.')) {
                    $key = substr($key, 5);
                }
                $feature = OptionalFeature::tryFrom($key);
                $path = match (true) {
                    // A module's own switch (outsourced orders) reports on the module list.
                    $feature?->module() !== null, str_starts_with($key, 'modules') => 'data.modules',
                    $feature !== null => 'data.features.'.$key,
                    default => 'data.'.$key,
                };
                $errors[$path] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->fillForm();
        $this->resetTable();

        Notification::make()->success()->title('تم حفظ إعدادات الدعم الفني')->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('سجل التغييرات')
            ->query(FeatureFlagAudit::query()->with('user')->latest('changed_at')->latest('id'))
            ->columns([
                TextColumn::make('changed_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                TextColumn::make('feature_key')->label('الإعداد')
                    ->formatStateUsing(fn (string $state): string => self::auditKeyLabel($state)),
                TextColumn::make('old')->label('من')
                    ->state(fn (FeatureFlagAudit $record): string => self::auditValueLabel($record->feature_key, $record->old_text, $record->old_value)),
                TextColumn::make('new')->label('إلى')
                    ->state(fn (FeatureFlagAudit $record): string => self::auditValueLabel($record->feature_key, $record->new_text, $record->new_value)),
                TextColumn::make('user.name')->label('بواسطة')->placeholder('النظام'),
            ])
            ->paginated([10, 25, 50]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ')
                ->icon('heroicon-m-check')
                ->requiresConfirmation(fn (): bool => ! $this->businessTypeLocked())
                ->modalDescription('نوع النشاط لا يمكن تغييره بعد تسجيل أول طلب. هل تريد المتابعة؟')
                ->action('save'),
        ];
    }

    public static function auditKeyLabel(string $key): string
    {
        if ($key === 'business_type') {
            return 'نوع النشاط';
        }

        if (str_starts_with($key, 'module.')) {
            $module = Module::tryFrom(substr($key, 7));

            return 'وحدة: '.($module?->label() ?? substr($key, 7));
        }

        return OptionalFeature::tryFrom($key)?->label() ?? $key;
    }

    public static function auditValueLabel(string $key, ?string $text, ?bool $value): string
    {
        if ($key === 'business_type') {
            return BusinessType::tryFrom((string) $text)?->label() ?? (string) $text;
        }

        if ($value === null) {
            return '-';
        }

        return $value ? 'مفعل' : 'متوقف';
    }
}
