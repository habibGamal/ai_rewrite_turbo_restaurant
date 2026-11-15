<?php

namespace App\Filament\Pages;

use App\Costing\CostingMethod;
use App\Costing\CostingMethodSwitcher;
use App\Enums\KdsSound;
use App\Enums\OptionalFeature;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Models\OrderTypeRow;
use App\Models\Product;
use App\Orders\CashierTabLayout;
use App\Orders\OrderTypeCatalog;
use App\Services\FeatureService;
use App\Services\PrintService;
use App\Services\Products\RecipeCycleDetectionService;
use App\Services\SettingsService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @property \Filament\Schemas\Schema $form
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_settings') ?? false;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected string $view = 'filament.pages.settings';

    protected static ?string $navigationLabel = 'الإعدادات';

    protected static ?string $title = 'إعدادات النظام';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 100;

    public ?array $data = [];

    public ?array $cycleDiagnosisResult = null;

    public function mount(): void
    {
        $settingsService = app(SettingsService::class);
        $settings = $settingsService->all();
        $defaults = $settingsService->getDefaults();

        // Initialize data with defaults first
        $this->data = $defaults;
        foreach ($this->data as $key => $value) {
            if (SettingKey::tryFrom($key)?->isBooleanSetting()) {
                $this->data[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Override with existing settings, ensuring they are strings or booleans
        foreach ($settings as $key => $value) {
            if (in_array($key, array_keys($defaults))) {
                if (SettingKey::tryFrom($key)?->isBooleanSetting()) {
                    $this->data[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } else {
                    $this->data[$key] = is_array($value) ? json_encode($value) : (string) $value;
                }
            }
        }

        // Decode the stored kds_order_type_targets JSON object into one
        // numeric state per order type so each input gets its own value.
        $targets = [];
        $storedTargets = $this->data[SettingKey::KDS_ORDER_TYPE_TARGETS->value] ?? '{}';
        if (is_string($storedTargets)) {
            $targets = json_decode($storedTargets, true) ?: [];
        }

        $this->data[SettingKey::KDS_ORDER_TYPE_TARGETS->value] = [];
        foreach (app(OrderTypeCatalog::class)->active() as $type) {
            $minutes = $targets[$type->code] ?? null;
            $this->data[SettingKey::KDS_ORDER_TYPE_TARGETS->value][$type->code] = ($minutes === null || $minutes === '')
                ? ''
                : (string) $minutes;
        }

        $this->data[SettingKey::CASHIER_TABS->value] = app(CashierTabLayout::class)->entriesForEditor();

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tabs\Tab::make('الإعدادات العامة')
                            ->icon('heroicon-m-cog-6-tooth')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('إعدادات النظام الأساسية')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make(SettingKey::WEBSITE_URL->value)
                                                    ->label(SettingKey::WEBSITE_URL->label())
                                                    ->helperText(SettingKey::WEBSITE_URL->helperText())
                                                    ->url()
                                                    ->required()
                                                    ->placeholder(SettingKey::WEBSITE_URL->placeholder()),

                                                TextInput::make(SettingKey::CASHIER_PRINTER_IP->value)
                                                    ->label(SettingKey::CASHIER_PRINTER_IP->label())
                                                    ->helperText(SettingKey::CASHIER_PRINTER_IP->helperText())
                                                    ->required()
                                                    ->placeholder(SettingKey::CASHIER_PRINTER_IP->placeholder()),

                                                TextInput::make(SettingKey::DINE_IN_SERVICE_CHARGE->value)
                                                    ->label(SettingKey::DINE_IN_SERVICE_CHARGE->label())
                                                    ->helperText(SettingKey::DINE_IN_SERVICE_CHARGE->helperText())
                                                    ->numeric()
                                                    ->step(0.01)
                                                    ->minValue(0)
                                                    ->maxValue(1)
                                                    ->required()
                                                    ->placeholder(SettingKey::DINE_IN_SERVICE_CHARGE->placeholder()),

                                                TextInput::make(SettingKey::SCALE_BARCODE_PREFIX->value)
                                                    ->label(SettingKey::SCALE_BARCODE_PREFIX->label())
                                                    ->helperText(SettingKey::SCALE_BARCODE_PREFIX->helperText())
                                                    ->required()
                                                    ->maxLength(4)
                                                    ->placeholder(SettingKey::SCALE_BARCODE_PREFIX->placeholder()),
                                            ]),

                                        Textarea::make(SettingKey::RECEIPT_FOOTER->value)
                                            ->label(SettingKey::RECEIPT_FOOTER->label())
                                            ->helperText(SettingKey::RECEIPT_FOOTER->helperText())
                                            ->placeholder(SettingKey::RECEIPT_FOOTER->placeholder())
                                            ->rows(3)
                                            ->maxLength(500),
                                    ]),
                            ]),

                        Tabs\Tab::make('إعدادات المطعم')
                            ->icon('heroicon-m-building-storefront')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('معلومات المطعم الأساسية')
                                    ->schema([
                                        TextInput::make(SettingKey::RESTAURANT_NAME->value)
                                            ->label(SettingKey::RESTAURANT_NAME->label())
                                            ->helperText(SettingKey::RESTAURANT_NAME->helperText())
                                            ->required()
                                            ->placeholder(SettingKey::RESTAURANT_NAME->placeholder())
                                            ->maxLength(255),

                                        Grid::make(2)
                                            ->schema([
                                                FileUpload::make(SettingKey::RESTAURANT_PRINT_LOGO->value)
                                                    ->label(SettingKey::RESTAURANT_PRINT_LOGO->label())
                                                    ->helperText(SettingKey::RESTAURANT_PRINT_LOGO->helperText())
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('logos')
                                                    ->visibility('public')
                                                    ->moveFiles()
                                                    ->acceptedFileTypes(['image/png'])
                                                    ->maxSize(2048) // 2MB limit
                                                    ->afterStateUpdated(function ($state) {
                                                        if ($state && is_string($state)) {
                                                            // Copy to public/images/logo.png
                                                            $sourcePath = Storage::disk('public')->path($state);
                                                            $destPath = public_path('images/logo.png');

                                                            if (file_exists($sourcePath)) {
                                                                @mkdir(dirname($destPath), 0755, true);
                                                                copy($sourcePath, $destPath);
                                                            }
                                                        }
                                                    }),

                                                FileUpload::make(SettingKey::RESTAURANT_OFFICIAL_LOGO->value)
                                                    ->label(SettingKey::RESTAURANT_OFFICIAL_LOGO->label())
                                                    ->helperText(SettingKey::RESTAURANT_OFFICIAL_LOGO->helperText())
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('logos')
                                                    ->visibility('public')
                                                    ->moveFiles()
                                                    ->acceptedFileTypes(['image/jpeg', 'image/jpg'])
                                                    ->maxSize(2048) // 2MB limit
                                                    ->afterStateUpdated(function ($state) {
                                                        if ($state && is_string($state)) {
                                                            // Copy to public/images/logo.jpg
                                                            $sourcePath = Storage::disk('public')->path($state);
                                                            $destPath = public_path('images/logo.jpg');

                                                            if (file_exists($sourcePath)) {
                                                                @mkdir(dirname($destPath), 0755, true);
                                                                copy($sourcePath, $destPath);
                                                            }
                                                        }
                                                    }),

                                                FileUpload::make(SettingKey::RESTAURANT_QR_LOGO->value)
                                                    ->label(SettingKey::RESTAURANT_QR_LOGO->label())
                                                    ->helperText(SettingKey::RESTAURANT_QR_LOGO->helperText())
                                                    ->image()
                                                    ->imageEditor()
                                                    ->directory('logos')
                                                    ->visibility('public')
                                                    ->moveFiles()
                                                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/jpg'])
                                                    ->maxSize(2048) // 2MB limit
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('إدارة الفروع')
                            ->icon('heroicon-m-building-office')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('إعدادات شبكة الفروع والاتصال')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Select::make(SettingKey::NODE_TYPE->value)
                                                    ->label(SettingKey::NODE_TYPE->label())
                                                    ->helperText(SettingKey::NODE_TYPE->helperText())
                                                    ->options([
                                                        'master' => 'رئيسية',
                                                        'slave' => 'فرع',
                                                        'independent' => 'مستقلة',
                                                    ])
                                                    ->required()
                                                    ->live(),

                                                TextInput::make(SettingKey::MASTER_NODE_LINK->value)
                                                    ->label(SettingKey::MASTER_NODE_LINK->label())
                                                    ->helperText(SettingKey::MASTER_NODE_LINK->helperText())
                                                    ->url()
                                                    ->placeholder(SettingKey::MASTER_NODE_LINK->placeholder())
                                                    ->visible(fn (Get $get): bool => $get(SettingKey::NODE_TYPE->value) === 'slave')
                                                    ->required(fn (Get $get): bool => $get(SettingKey::NODE_TYPE->value) === 'slave'),
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('إدارة الورديات')
                            ->icon('heroicon-m-clock')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('إعدادات خاصة بإدارة الورديات والطلبات')
                                    ->schema([
                                        Toggle::make(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->value)
                                            ->label(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->label())
                                            ->helperText(SettingKey::ALLOW_WEB_ORDERS_SHIFT_TRANSFER->helperText())
                                            ->inline(false),
                                    ]),
                            ]),

                        Tabs\Tab::make('تبويبات الكاشير')
                            ->icon('heroicon-m-view-columns')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make('طريقة العرض')
                                    ->schema([
                                        Radio::make(SettingKey::CASHIER_TABS_ORIENTATION->value)
                                            ->label(SettingKey::CASHIER_TABS_ORIENTATION->label())
                                            ->helperText(SettingKey::CASHIER_TABS_ORIENTATION->helperText())
                                            ->options([
                                                'horizontal' => 'أفقي (أعلى الشاشة)',
                                                'vertical' => 'عمودي (جانب الشاشة)',
                                            ])
                                            ->inline()
                                            ->default('horizontal')
                                            ->required(),
                                    ]),

                                Section::make('ترتيب وظهور التبويبات')
                                    ->description(SettingKey::CASHIER_TABS->helperText())
                                    ->schema([
                                        Repeater::make(SettingKey::CASHIER_TABS->value)
                                            ->hiddenLabel()
                                            ->table([
                                                TableColumn::make('التبويب'),
                                                TableColumn::make('مرئي')
                                                    ->width('120px')
                                                    ->alignment(Alignment::Center),
                                            ])
                                            ->schema([
                                                Hidden::make('key'),

                                                TextInput::make('label')
                                                    ->label('التبويب')
                                                    ->hiddenLabel()
                                                    ->disabled()
                                                    ->dehydrated(false),

                                                Toggle::make('visible')
                                                    ->label('مرئي')
                                                    ->hiddenLabel(),
                                            ])
                                            ->addable(false)
                                            ->deletable(false)
                                            ->reorderable()
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tabs\Tab::make('إدارة المشتريات')
                            ->icon('heroicon-m-shopping-cart')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('إعدادات خاصة بفواتير الشراء وتكاليف المنتجات')
                                    ->schema([
                                        Select::make(SettingKey::COSTING_METHOD->value)
                                            ->label(SettingKey::COSTING_METHOD->label())
                                            ->helperText(SettingKey::COSTING_METHOD->helperText())
                                            ->options(CostingMethod::options())
                                            ->required(),
                                    ]),
                            ]),

                        Tabs\Tab::make('شاشة المطبخ (KDS)')
                            ->icon('heroicon-m-tv')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('إعدادات نظام عرض المطبخ')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                Toggle::make(SettingKey::KDS_ITEM_TRACKING->value)
                                                    ->label(SettingKey::KDS_ITEM_TRACKING->label())
                                                    ->helperText(SettingKey::KDS_ITEM_TRACKING->helperText())
                                                    ->inline(false),

                                                Toggle::make(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->value)
                                                    ->label(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->label())
                                                    ->helperText(SettingKey::KDS_AUTO_FINISH_ON_COMPLETE->helperText())
                                                    ->inline(false),

                                                Toggle::make(SettingKey::KDS_CASHIER_STATUS_ENABLED->value)
                                                    ->label(SettingKey::KDS_CASHIER_STATUS_ENABLED->label())
                                                    ->helperText(SettingKey::KDS_CASHIER_STATUS_ENABLED->helperText())
                                                    ->inline(false),
                                            ]),

                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make(SettingKey::KDS_DEFAULT_PREP_MINUTES->value)
                                                    ->label(SettingKey::KDS_DEFAULT_PREP_MINUTES->label())
                                                    ->helperText(SettingKey::KDS_DEFAULT_PREP_MINUTES->helperText())
                                                    ->numeric()
                                                    ->rules(SettingKey::KDS_DEFAULT_PREP_MINUTES->validationRules())
                                                    ->placeholder(SettingKey::KDS_DEFAULT_PREP_MINUTES->placeholder()),

                                                TextInput::make(SettingKey::KDS_WARNING_PERCENT->value)
                                                    ->label(SettingKey::KDS_WARNING_PERCENT->label())
                                                    ->helperText(SettingKey::KDS_WARNING_PERCENT->helperText())
                                                    ->numeric()
                                                    ->suffix('%')
                                                    ->rules(SettingKey::KDS_WARNING_PERCENT->validationRules())
                                                    ->placeholder(SettingKey::KDS_WARNING_PERCENT->placeholder()),

                                                TextInput::make(SettingKey::KDS_RECALL_MINUTES->value)
                                                    ->label(SettingKey::KDS_RECALL_MINUTES->label())
                                                    ->helperText(SettingKey::KDS_RECALL_MINUTES->helperText())
                                                    ->numeric()
                                                    ->rules(SettingKey::KDS_RECALL_MINUTES->validationRules())
                                                    ->placeholder(SettingKey::KDS_RECALL_MINUTES->placeholder()),

                                                TextInput::make(SettingKey::KDS_SOUND_VOLUME->value)
                                                    ->label(SettingKey::KDS_SOUND_VOLUME->label())
                                                    ->helperText(SettingKey::KDS_SOUND_VOLUME->helperText())
                                                    ->numeric()
                                                    ->rules(SettingKey::KDS_SOUND_VOLUME->validationRules())
                                                    ->placeholder(SettingKey::KDS_SOUND_VOLUME->placeholder()),

                                                TextInput::make(SettingKey::KDS_REMINDER_SECONDS->value)
                                                    ->label(SettingKey::KDS_REMINDER_SECONDS->label())
                                                    ->helperText(SettingKey::KDS_REMINDER_SECONDS->helperText())
                                                    ->numeric()
                                                    ->rules(SettingKey::KDS_REMINDER_SECONDS->validationRules())
                                                    ->placeholder(SettingKey::KDS_REMINDER_SECONDS->placeholder()),
                                            ]),

                                        Grid::make(2)
                                            ->schema([
                                                Select::make(SettingKey::KDS_NEW_ORDER_SOUND->value)
                                                    ->label(SettingKey::KDS_NEW_ORDER_SOUND->label())
                                                    ->helperText(SettingKey::KDS_NEW_ORDER_SOUND->helperText())
                                                    ->options($this->kdsSoundOptions())
                                                    ->rules(SettingKey::KDS_NEW_ORDER_SOUND->validationRules())
                                                    ->placeholder(SettingKey::KDS_NEW_ORDER_SOUND->placeholder()),

                                                Select::make(SettingKey::KDS_CHANGE_SOUND->value)
                                                    ->label(SettingKey::KDS_CHANGE_SOUND->label())
                                                    ->helperText(SettingKey::KDS_CHANGE_SOUND->helperText())
                                                    ->options($this->kdsSoundOptions())
                                                    ->rules(SettingKey::KDS_CHANGE_SOUND->validationRules())
                                                    ->placeholder(SettingKey::KDS_CHANGE_SOUND->placeholder()),

                                                Select::make(SettingKey::KDS_CANCEL_SOUND->value)
                                                    ->label(SettingKey::KDS_CANCEL_SOUND->label())
                                                    ->helperText(SettingKey::KDS_CANCEL_SOUND->helperText())
                                                    ->options($this->kdsSoundOptions())
                                                    ->rules(SettingKey::KDS_CANCEL_SOUND->validationRules())
                                                    ->placeholder(SettingKey::KDS_CANCEL_SOUND->placeholder()),
                                            ]),

                                        Fieldset::make('الوقت المستهدف حسب نوع الطلب (اختياري)')
                                            ->columns(3)
                                            ->schema(app(OrderTypeCatalog::class)->active()
                                                ->map(fn (OrderTypeRow $type): TextInput => TextInput::make(SettingKey::KDS_ORDER_TYPE_TARGETS->value.'.'.$type->code)
                                                    ->label($type->label)
                                                    ->helperText('يُستخدم عندما لا يحدد الصنف أو التصنيف وقتاً')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->maxValue(240)
                                                    ->placeholder('-')
                                                    ->nullable())
                                                ->all()),
                                    ]),
                            ]),

                        Tabs\Tab::make('المميزات')
                            ->icon('heroicon-m-sparkles')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make()
                                    ->description('تفعيل أو إيقاف المميزات الاختيارية للنظام. عند إيقاف ميزة يتم إخفاء شاشاتها مع الاحتفاظ بالسجلات التاريخية.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Toggle::make(SettingKey::FEATURE_REVENUES_ENABLED->value)
                                                    ->label(SettingKey::FEATURE_REVENUES_ENABLED->label())
                                                    ->helperText(SettingKey::FEATURE_REVENUES_ENABLED->helperText())
                                                    ->inline(false),

                                                Toggle::make(SettingKey::FEATURE_CUSTOM_FEES_ENABLED->value)
                                                    ->label(SettingKey::FEATURE_CUSTOM_FEES_ENABLED->label())
                                                    ->helperText(SettingKey::FEATURE_CUSTOM_FEES_ENABLED->helperText())
                                                    ->inline(false),

                                                Toggle::make(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->value)
                                                    ->label(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->label())
                                                    ->helperText(SettingKey::FEATURE_OUTSOURCED_ORDERS_ENABLED->helperText())
                                                    ->inline(false),

                                                Toggle::make(SettingKey::FEATURE_EQUIPMENT_ENABLED->value)
                                                    ->label(SettingKey::FEATURE_EQUIPMENT_ENABLED->label())
                                                    ->helperText(SettingKey::FEATURE_EQUIPMENT_ENABLED->helperText())
                                                    ->inline(false),
                                            ]),
                                    ]),
                            ]),

                        Tabs\Tab::make('التشخيص والصيانة')
                            ->icon('heroicon-m-wrench-screwdriver')
                            ->badge(fn (Tabs\Tab $component): ?int => $this->tabErrorCount($component))
                            ->badgeColor('danger')
                            ->schema([
                                Section::make('تشخيص مكونات المنتجات المصنعة (Cyclic Dependencies)')
                                    ->description('فحص مكونات المنتجات المصنعة للتأكد من خلوها من التبعيات الدائرية والإشارات الذاتية التي تسبب استهلاك الذاكرة وتوقف النظام عند إتمام الطلبات.')
                                    ->schema([
                                        Actions::make([
                                            Action::make('checkCyclicDependencies')
                                                ->label('فحص التبعيات الدائرية')
                                                ->icon('heroicon-o-magnifying-glass')
                                                ->color('warning')
                                                ->action('runCycleDiagnosis'),

                                            Action::make('fixCyclicDependencies')
                                                ->label('إصلاح التبعيات الذاتية تلقائياً')
                                                ->icon('heroicon-o-wrench')
                                                ->color('danger')
                                                ->requiresConfirmation()
                                                ->modalHeading('تأكيد إصلاح التبعيات الدائرية')
                                                ->modalDescription('سيتم حذف المكونات التي تشير إلى المنتج نفسه مباشرة لمنع انهيار النظام عند إتمام الطلبات. هل أنت متأكد؟')
                                                ->modalSubmitActionLabel('نعم، قم بالإصلاح')
                                                ->visible(fn () => ! empty($this->cycleDiagnosisResult['cycles']))
                                                ->action('fixCyclicDependenciesAction'),
                                        ]),

                                        View::make('filament.pages.partials.cycle-diagnosis-results'),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    private function tabErrorCount(Tabs\Tab $tab): ?int
    {
        $errors = $this->getErrorBag()->getMessages();
        $fields = $tab->getChildSchema()->getFlatFields(withHidden: true);
        $count = 0;

        foreach (array_keys($errors) as $errorPath) {
            foreach ($fields as $field) {
                $path = $field->getStatePath();
                if ($errorPath === $path || str_starts_with($errorPath, $path.'.')) {
                    $count++;
                    break;
                }
            }
        }

        return $count ?: null;
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            // Encode the optional per-order-type minutes back into a JSON
            // object string, dropping empty entries for order types
            // that were not given a target.
            $targets = [];
            $targetsState = $data[SettingKey::KDS_ORDER_TYPE_TARGETS->value] ?? [];
            if (is_array($targetsState)) {
                foreach (app(OrderTypeCatalog::class)->active() as $type) {
                    $minutes = $targetsState[$type->code] ?? null;
                    if ($minutes === null || (is_string($minutes) && trim($minutes) === '')) {
                        continue;
                    }

                    $targets[$type->code] = (int) $minutes;
                }
            }
            unset($data[SettingKey::KDS_ORDER_TYPE_TARGETS->value]);

            $cashierTabs = $data[SettingKey::CASHIER_TABS->value] ?? [];
            unset($data[SettingKey::CASHIER_TABS->value]);
            $cashierTabs = is_array($cashierTabs) ? $cashierTabs : [];

            // Ensure all values are strings before saving
            $cleanData = [];
            $featureStates = [];
            foreach ($data as $key => $value) {
                $settingKey = SettingKey::tryFrom($key);
                if ($settingKey?->isFeatureFlag()) {
                    $featureStates[$key] = (bool) $value;

                    continue;
                }

                if ($settingKey?->isBooleanSetting()) {
                    $cleanData[$key] = $value ? 'true' : 'false';
                } else {
                    $cleanData[$key] = is_array($value) ? json_encode($value) : (string) $value;
                }
            }

            $cleanData[SettingKey::KDS_ORDER_TYPE_TARGETS->value] = count($targets) > 0
                ? json_encode($targets)
                : '{}';

            DB::transaction(function () use ($cashierTabs, $cleanData, $featureStates) {
                try {
                    app(CashierTabLayout::class)->save($cashierTabs);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages([
                        'data.'.SettingKey::CASHIER_TABS->value => $e->validator->errors()->first(SettingKey::CASHIER_TABS->value),
                    ]);
                }

                app(CostingMethodSwitcher::class)->switchTo(CostingMethod::from($cleanData[SettingKey::COSTING_METHOD->value]));
                unset($cleanData[SettingKey::COSTING_METHOD->value]);
                app(FeatureService::class)->updateFeatures($featureStates, auth()->id());
                app(SettingsService::class)->setMultiple($cleanData);
            });

            Notification::make()
                ->title('تم حفظ الإعدادات بنجاح')
                ->body('تم حفظ جميع الإعدادات في قاعدة البيانات')
                ->icon('heroicon-o-check-circle')
                ->iconColor('success')
                ->send();

        } catch (ValidationException $e) {
            // Let Filament/Livewire show the validation errors
            throw $e;
        } catch (Exception $e) {
            logger()->error('Error saving settings: '.$e->getMessage());
            Notification::make()
                ->title('خطأ في حفظ الإعدادات')
                ->body('حدث خطأ أثناء محاولة حفظ الإعدادات. يرجى المحاولة مرة أخرى.')
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->send();
        }
    }

    public function resetToDefaults(): void
    {
        try {
            $settingsService = app(SettingsService::class);
            $defaults = $settingsService->getDefaults();

            DB::transaction(function () use ($defaults, $settingsService): void {
                app(CostingMethodSwitcher::class)->switchTo(CostingMethod::from($defaults[SettingKey::COSTING_METHOD->value]));
                $remainingDefaults = $defaults;
                unset($remainingDefaults[SettingKey::COSTING_METHOD->value]);

                $featureDefaults = [];
                foreach (OptionalFeature::cases() as $feature) {
                    $key = $feature->value;
                    if (array_key_exists($key, $remainingDefaults)) {
                        $featureDefaults[$key] = filter_var($remainingDefaults[$key], FILTER_VALIDATE_BOOLEAN);
                        unset($remainingDefaults[$key]);
                    }
                }

                app(FeatureService::class)->updateFeatures($featureDefaults, auth()->id());
                $settingsService->setMultiple($remainingDefaults);
            });

            $this->mount();

            Notification::make()
                ->title('تم إعادة تعيين الإعدادات إلى القيم الافتراضية')
                ->body('تم إعادة تعيين جميع الإعدادات بنجاح')
                ->icon('heroicon-o-arrow-path')
                ->iconColor('success')
                ->send();

        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            Notification::make()
                ->title('خطأ في إعادة تعيين الإعدادات')
                ->body((string) (collect($e->errors())->flatten()->first() ?: 'حدث خطأ أثناء محاولة إعادة تعيين الإعدادات.'))
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title('خطأ في إعادة تعيين الإعدادات')
                ->body('حدث خطأ أثناء محاولة إعادة تعيين الإعدادات.')
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->send();
        }
    }

    public function testCashierPrinter(): void
    {
        try {
            $printService = app(PrintService::class);
            $printService->testCashierPrinter();

            Notification::make()
                ->title('تم إرسال الاختبار إلى الطابعة')
                ->body('تم إرسال اختبار الطباعة بنجاح. تحقق من الطابعة للتأكد من وصول الاختبار.')
                ->icon('heroicon-o-printer')
                ->iconColor('success')
                ->send();

        } catch (Exception $e) {
            Notification::make()
                ->title('فشل في اختبار الطابعة')
                ->body('حدث خطأ أثناء محاولة اختبار الطابعة: '.$e->getMessage())
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->send();
        }
    }

    /**
     * Options for the KDS sound selects: sound value => Arabic label.
     *
     * @return array<string, string>
     */
    private function kdsSoundOptions(): array
    {
        return collect(KdsSound::cases())
            ->mapWithKeys(fn (KdsSound $sound): array => [$sound->value => $sound->getLabel()])
            ->all();
    }

    public function runCycleDiagnosis(): void
    {
        $cycleDetector = app(RecipeCycleDetectionService::class);
        $cycles = $cycleDetector->findAllCycles();
        $totalManufactured = Product::where('type', ProductType::Manufactured)->count();

        $this->cycleDiagnosisResult = [
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'checked_count' => $totalManufactured,
            'cycles' => $cycles,
        ];

        if (empty($cycles)) {
            Notification::make()
                ->title('الفحص سليم')
                ->body('لم يتم العثور على أي تبعيات دائرية في مكونات المنتجات المصنعة.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('تم اكتشاف تبعيات دائرية!')
                ->body('تم العثور على '.count($cycles).' تبعية دائرية في مكونات المنتجات المصنعة.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function fixCyclicDependenciesAction(): void
    {
        $cycleDetector = app(RecipeCycleDetectionService::class);
        $fixedCount = $cycleDetector->fixSelfReferencingComponents();

        $this->runCycleDiagnosis();

        Notification::make()
            ->title('تم الإصلاح بنجاح')
            ->body("تم حذف {$fixedCount} من المكونات الدائرية غير الصالحة.")
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        $actions = [
            Action::make('save')
                ->label('حفظ الإعدادات')
                ->icon('heroicon-m-check')
                ->color('primary')
                ->action('save'),

            Action::make('testPrinter')
                ->label('اختبار الطابعة')
                ->icon('heroicon-m-printer')
                ->color('info')
                ->action('testCashierPrinter'),

            Action::make('reset')
                ->label('إعادة تعيين للافتراضي')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('إعادة تعيين الإعدادات')
                ->modalDescription('هل أنت متأكد من أنك تريد إعادة تعيين جميع الإعدادات إلى القيم الافتراضية؟ سيتم فقدان التغييرات الحالية.')
                ->modalSubmitActionLabel('نعم، إعادة تعيين')
                ->action('resetToDefaults'),

        ];

        return $actions;
    }
}
