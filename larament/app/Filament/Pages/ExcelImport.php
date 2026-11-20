<?php

namespace App\Filament\Pages;

use App\Services\SpecificDataImportService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * @property Schema $form
 */
class ExcelImport extends Page implements HasForms
{
    use InteractsWithForms;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_excel_import') ?? false;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'رفع ملف Excel / JSON';

    protected static ?string $title = 'رفع ملف Excel / JSON';

    protected string $view = 'filament.pages.excel-import';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    public ?array $data = [];

    public ?array $analysisResult = null;

    public ?array $importResult = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    /**
     * Helper method to safely get the uploaded file or path from form data.
     */
    private function getUploadedFile(): mixed
    {
        $file = $this->data['excel_file'] ?? null;

        if (is_array($file)) {
            return array_values($file)[0] ?? null;
        }

        return $file;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('رفع ملف Excel / JSON')
                    ->description('اختر ملف Excel (.xlsx) أو ملف JSON (.json) لرفعه وتحليل بياناته')
                    ->schema([
                        FileUpload::make('excel_file')
                            ->label('ملف Excel أو JSON')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'application/json',
                                'text/json',
                                'text/plain',
                                'text/x-json',
                            ])
                            ->maxSize(10240) // 10MB
                            ->directory('excel-imports')
                            ->preserveFilenames()
                            ->required()
                            ->multiple(false) // Ensure single file upload
                            ->reactive()
                            ->afterStateUpdated(function ($state) {
                                $this->analysisResult = null;
                                $this->importResult = null;
                            }),

                        Actions::make([
                            Action::make('analyze')
                                ->label('تحليل الملف')
                                ->icon('heroicon-o-magnifying-glass')
                                ->color('info')
                                ->action('analyzeFile'),

                            Action::make('import')
                                ->label('استيراد البيانات')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('success')
                                ->visible(fn () => ! empty($this->analysisResult) && $this->analysisResult['success'])
                                ->requiresConfirmation()
                                ->modalHeading('تأكيد الاستيراد')
                                ->modalDescription('هل أنت متأكد من أنك تريد استيراد هذه البيانات؟')
                                ->modalSubmitActionLabel('نعم، استيراد')
                                ->action('importData'),
                        ])->fullWidth(),
                    ]),

                Section::make('نتائج التحليل')
                    ->description('معلومات حول هيكل الملف والبيانات المكتشفة')
                    ->visible(fn () => ! empty($this->analysisResult))
                    ->schema([
                        ViewField::make('analysis_info')
                            ->label('')
                            ->reactive()
                            ->view('filament.pages.partials.analysis-results', [
                                'analysisResult' => $this->analysisResult,
                            ]),
                    ]),

                Section::make('نتائج الاستيراد')
                    ->description('تفاصيل عملية الاستيراد')
                    ->visible(fn () => ! empty($this->importResult))
                    ->schema([
                        Placeholder::make('import_info')
                            ->label('')
                            ->content(fn () => view('filament.pages.partials.import-results', [
                                'importResult' => $this->importResult,
                            ])->render()),
                    ]),
            ])
            ->statePath('data');
    }

    public function analyzeFile(): void
    {
        $this->validate();

        $file = $this->getUploadedFile();

        if (! $file) {
            Notification::make()
                ->title('خطأ')
                ->body('يرجى اختيار ملف أولاً')
                ->danger()
                ->send();

            return;
        }

        try {
            $service = new SpecificDataImportService;
            $this->analysisResult = $service->analyzeStructure($file);

            if ($this->analysisResult['success']) {
                Notification::make()
                    ->title('نجح التحليل')
                    ->body('تم تحليل الملف بنجاح')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('خطأ في التحليل')
                    ->body($this->analysisResult['error'] ?? 'خطأ غير معروف أثناء تحليل الملف')
                    ->danger()
                    ->send();
            }
        } catch (Exception $e) {
            Notification::make()
                ->title('خطأ')
                ->body('حدث خطأ أثناء تحليل الملف: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function importData(): void
    {
        $file = $this->getUploadedFile();

        if (! $file) {
            Notification::make()
                ->title('خطأ')
                ->body('يرجى اختيار ملف أولاً')
                ->danger()
                ->send();

            return;
        }

        try {
            $service = new SpecificDataImportService;
            $this->importResult = $service->importData($file);

            if ($this->importResult['success']) {
                $imported = $this->importResult['imported_count'];
                $errors = $this->importResult['error_count'];

                Notification::make()
                    ->title('نجح الاستيراد')
                    ->body("تم استيراد {$imported} سجل بنجاح".($errors > 0 ? " مع {$errors} خطأ" : ''))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('خطأ في الاستيراد')
                    ->body($this->importResult['error'] ?? 'خطأ غير معروف أثناء استيراد البيانات')
                    ->danger()
                    ->send();
            }
        } catch (Exception $e) {
            Notification::make()
                ->title('خطأ')
                ->body('حدث خطأ أثناء استيراد البيانات: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
