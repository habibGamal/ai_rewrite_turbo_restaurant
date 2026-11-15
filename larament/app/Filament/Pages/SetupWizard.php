<?php

namespace App\Filament\Pages;

use App\Enums\SettingKey;
use App\Exceptions\BusinessTypeLockedException;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Services\Setup\BusinessProfileService;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * First-run setup for a new install, done by the vendor: business type,
 * modules, the shop's name, then the starting data for that type. The
 * vendor lands here after signing in until it is finished; installs that
 * were already in use are marked as set up by their upgrade migration.
 *
 * @property Schema $form
 */
class SetupWizard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'معالج الإعداد';

    protected static ?string $title = 'إعداد النظام لأول مرة';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'setup';

    protected string $view = 'filament.pages.setup-wizard';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && ! app(BusinessProfileService::class)->completed();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('vendor.console') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $features = app(FeatureService::class);
        $type = $features->businessType();

        $this->form->fill([
            'business_type' => $type->value,
            'modules' => array_map(fn (Module $module): string => $module->value, $features->enabledModules()),
            'business_name' => app(SettingsService::class)->get(SettingKey::RESTAURANT_NAME->value, ''),
        ]);
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
                Wizard::make([
                    Step::make('النشاط')
                        ->description('نوع النشاط يحدد الوحدات والشاشات')
                        ->schema([
                            Text::make($locked
                                ? 'هذه النسخة سجلت طلبات بالفعل، لذلك لا يمكن تغيير نوع النشاط.'
                                : 'يُحدد مرة واحدة ولا يمكن تغييره بعد أول طلب.'),
                            Radio::make('business_type')
                                ->hiddenLabel()
                                ->options(BusinessType::options())
                                ->descriptions(array_combine(
                                    array_column(BusinessType::cases(), 'value'),
                                    array_map(fn (BusinessType $type): string => $type->description(), BusinessType::cases()),
                                ))
                                ->required()
                                ->disabled($locked)
                                ->live()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    $type = BusinessType::tryFrom((string) $state);
                                    if ($type === null) {
                                        return;
                                    }

                                    // External sellers is never a default; keep the current choice.
                                    $modules = array_map(fn (Module $module): string => $module->value, $type->defaultModules());
                                    if (in_array(Module::EXTERNAL_SELLERS->value, (array) $get('modules'), true)) {
                                        $modules[] = Module::EXTERNAL_SELLERS->value;
                                    }
                                    $set('modules', $modules);
                                }),
                        ]),
                    Step::make('الوحدات')
                        ->description('ما يظهر في هذه النسخة')
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
                    Step::make('بيانات المتجر')
                        ->description('يظهر على الفواتير والشاشات')
                        ->schema([
                            TextInput::make('business_name')
                                ->label('اسم المتجر')
                                ->required()
                                ->maxLength(255),
                        ]),
                ])
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit" size="sm">إنهاء الإعداد</x-filament::button>'
                    ))),
            ])
            ->statePath('data');
    }

    public function finish(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $profile = app(BusinessProfileService::class);
        $features = app(FeatureService::class);
        $type = BusinessType::tryFrom((string) ($data['business_type'] ?? '')) ?? $features->businessType();
        $modules = $data['modules'] ?? [];

        try {
            if ($this->businessTypeLocked()) {
                // Too late for the type and its starting data; still save the rest.
                $features->setModules($modules, auth()->id());
                app(SettingsService::class)->set(SettingKey::RESTAURANT_NAME->value, trim((string) $data['business_name']));
                $profile->markCompleted();
            } else {
                $profile->setUp($type, $modules, (string) $data['business_name'], auth()->id());
            }
        } catch (BusinessTypeLockedException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['data.modules' => collect($e->errors())->flatten()->implode(' ')]);
        }

        Notification::make()->success()->title('تم إعداد النظام')->send();

        $this->redirect(VendorConsole::getUrl());
    }
}
