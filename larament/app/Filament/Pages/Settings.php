<?php

namespace App\Filament\Pages;

use App\Filament\Traits\AdminAccess;
use App\Services\SettingsService;
use App\Services\PrintService;
use App\Enums\SettingKey;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms, AdminAccess;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string $view = 'filament.pages.settings';
    protected static ?string $navigationLabel = 'الإعدادات';
    protected static ?string $title = 'إعدادات النظام';
    protected static ?string $navigationGroup = 'إدارة النظام';
    protected static ?int $navigationSort = 100;

    public ?array $data = [];

    public function mount(): void
    {
        $settingsService = app(SettingsService::class);
        $settings = $settingsService->all();
        $defaults = $settingsService->getDefaults();

        // Initialize data with defaults first
        $this->data = $defaults;

        // Override with existing settings, ensuring they are strings
        foreach ($settings as $key => $value) {
            if (in_array($key, array_keys($defaults))) {
                $this->data[$key] = is_array($value) ? json_encode($value) : (string) $value;
            }
        }

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('الإعدادات العامة')
                    ->description('إعدادات النظام الأساسية')
                    ->icon('heroicon-m-cog-6-tooth')
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
                            ]),

                        Textarea::make(SettingKey::RECEIPT_FOOTER->value)
                            ->label(SettingKey::RECEIPT_FOOTER->label())
                            ->helperText(SettingKey::RECEIPT_FOOTER->helperText())
                            ->placeholder(SettingKey::RECEIPT_FOOTER->placeholder())
                            ->rows(3)
                            ->maxLength(500),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            // Ensure all values are strings before saving
            $cleanData = [];
            foreach ($data as $key => $value) {
                $cleanData[$key] = is_array($value) ? json_encode($value) : (string) $value;
            }

            $settingsService = app(SettingsService::class);
            $settingsService->setMultiple($cleanData);

            Notification::make()
                ->title('تم حفظ الإعدادات بنجاح')
                ->body('تم حفظ جميع الإعدادات في قاعدة البيانات')
                ->icon('heroicon-o-check-circle')
                ->iconColor('success')
                ->send();

        } catch (\Exception $e) {
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

            $settingsService->setMultiple($defaults);
            $this->data = $defaults;
            $this->form->fill($this->data);

            Notification::make()
                ->title('تم إعادة تعيين الإعدادات إلى القيم الافتراضية')
                ->body('تم إعادة تعيين جميع الإعدادات بنجاح')
                ->icon('heroicon-o-arrow-path')
                ->iconColor('success')
                ->send();

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
            Notification::make()
                ->title('فشل في اختبار الطابعة')
                ->body('حدث خطأ أثناء محاولة اختبار الطابعة: ' . $e->getMessage())
                ->icon('heroicon-o-x-circle')
                ->iconColor('danger')
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
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
    }

}
