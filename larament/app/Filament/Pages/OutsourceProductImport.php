<?php

namespace App\Filament\Pages;

use App\Models\ExternalSeller;
use App\Services\FeatureService;
use App\Services\OutsourceProductImportService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
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
class OutsourceProductImport extends Page implements HasForms
{
    use InteractsWithForms;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_excel_import')
            || auth()->user()?->can('view_external_sellers')
            || auth()->user()?->can('create_external_sellers')
            || (auth()->user()?->hasRole('admin') ?? false);
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'استيراد أصناف Outsource';

    protected static ?string $title = 'استيراد أصناف البائعين الخارجيين (Outsource)';

    protected string $view = 'filament.pages.outsource-product-import';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    protected static ?int $navigationSort = 50;

    public ?array $data = [];

    public ?array $analysisResult = null;

    public ?array $importResult = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    /**
     * Helper method to safely get the uploaded file from form data.
     */
    private function getUploadedFile(): mixed
    {
        $file = $this->data['import_file'] ?? null;

        if (is_array($file)) {
            return array_values($file)[0] ?? null;
        }

        return $file;
    }

    public function form(Schema $schema): Schema
    {
        $isOutsourceEnabled = app(FeatureService::class)->outsourcedOrdersEnabled();

        return $schema
            ->components([
                Section::make('رفع ملف الأصناف')
                    ->description($isOutsourceEnabled
                        ? 'اختر ملف JSON أو Excel يحتوي على قائمة أصناف البائع الخارجي لرفعها وتحديثها'
                        : 'تنبيه: ميزة البائعين الخارجيين (Outsourced Orders) غير مفعلة حالياً في النظام. يمكنك استيراد الأصناف الآن واستخدامها بمجرد تفعيل الميزة.')
                    ->schema([
                        Select::make('seller_id')
                            ->label('البائع الخارجي (اختياري)')
                            ->placeholder('اكتشاف تلقائي من الملف أو اختر بائعاً...')
                            ->options(fn () => ExternalSeller::query()->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('إذا تُرِك فارغاً، سيتم استخدام بيانات البائع المحددة داخل الملف، أو إنشاء بائع جديد تلقائياً.'),

                        FileUpload::make('import_file')
                            ->label('ملف المنتجات (JSON أو Excel)')
                            ->acceptedFileTypes([
                                'application/json',
                                'text/json',
                                'text/plain',
                                'text/x-json',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->maxSize(10240) // 10MB
                            ->directory('outsource-imports')
                            ->preserveFilenames()
                            ->required()
                            ->multiple(false)
                            ->reactive()
                            ->afterStateUpdated(function () {
                                $this->analysisResult = null;
                                $this->importResult = null;
                            }),

                        Actions::make([
                            Action::make('analyze')
                                ->label('تحليل الملف والمعاينة')
                                ->icon('heroicon-o-magnifying-glass')
                                ->color('info')
                                ->action('analyzeFile'),

                            Action::make('import')
                                ->label('استيراد الأصناف الآن')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('success')
                                ->visible(fn () => ! empty($this->analysisResult) && ($this->analysisResult['success'] ?? false))
                                ->requiresConfirmation()
                                ->modalHeading('تأكيد استيراد الأصناف الخارجية')
                                ->modalDescription('هل أنت متأكد من رغبتك في استيراد الأصناف إلى قاعدة البيانات؟')
                                ->modalSubmitActionLabel('نعم، استيراد الأصناف')
                                ->action('importData'),
                        ])->fullWidth(),
                    ]),

                Section::make('نتائج التحليل والمعاينة')
                    ->description('تفاصيل البائع والأصناف المكتشفة من الملف')
                    ->visible(fn () => ! empty($this->analysisResult))
                    ->schema([
                        ViewField::make('analysis_info')
                            ->label('')
                            ->reactive()
                            ->view('filament.pages.partials.outsource-analysis-results', [
                                'analysisResult' => $this->analysisResult,
                            ]),
                    ]),

                Section::make('نتائج الاستيراد')
                    ->description('ملخص عملية الاستيراد في قاعدة البيانات')
                    ->visible(fn () => ! empty($this->importResult))
                    ->schema([
                        Placeholder::make('import_info')
                            ->label('')
                            ->content(fn () => view('filament.pages.partials.outsource-import-results', [
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

        $sellerId = ! empty($this->data['seller_id']) ? (int) $this->data['seller_id'] : null;

        try {
            $service = new OutsourceProductImportService;
            $this->analysisResult = $service->analyzeStructure($file, $sellerId);

            if ($this->analysisResult['success']) {
                $count = $this->analysisResult['total_products'] ?? 0;
                $sellerName = $this->analysisResult['seller']['name'] ?? 'غير محدد';

                Notification::make()
                    ->title('نجح التحليل')
                    ->body("تم العثور على {$count} صنف للبائع '{$sellerName}'.")
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

        $sellerId = ! empty($this->data['seller_id']) ? (int) $this->data['seller_id'] : null;

        try {
            $service = new OutsourceProductImportService;
            $this->importResult = $service->importData($file, $sellerId);

            if ($this->importResult['success']) {
                $imported = $this->importResult['imported_count'];
                $errors = $this->importResult['error_count'];
                $sellerName = $this->importResult['seller']['name'] ?? '';

                Notification::make()
                    ->title('نجح الاستيراد')
                    ->body("تم استيراد {$imported} صنف بنجاح للبائع '{$sellerName}'".($errors > 0 ? " مع {$errors} خطأ" : ''))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('خطأ في الاستيراد')
                    ->body($this->importResult['error'] ?? 'خطأ غير معروف أثناء الاستيراد')
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
