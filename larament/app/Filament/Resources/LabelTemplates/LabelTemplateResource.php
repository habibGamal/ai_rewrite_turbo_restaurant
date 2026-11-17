<?php

namespace App\Filament\Resources\LabelTemplates;

use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\ListLabelTemplates;
use App\Labels\LabelData;
use App\Labels\LabelElementType;
use App\Labels\LabelPrintJobs;
use App\Labels\LabelRenderer;
use App\Models\LabelTemplate;
use App\Modules\ModuleScreens;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;

/**
 * «تصميمات الملصقات»: each shop's barcode label designs. Hidden (404)
 * while label printing is off, see ModuleScreens.
 */
class LabelTemplateResource extends Resource
{
    protected static ?string $model = LabelTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return ModuleScreens::allows(static::class) && parent::shouldRegisterNavigation();
    }

    public static function getModelLabel(): string
    {
        return 'تصميم ملصق';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تصميمات الملصقات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المقاس')
                ->description('مقاس الملصق الواحد بالمللي كما هو مكتوب على رول الملصقات.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('اسم التصميم')
                        ->required()
                        ->maxLength(100),
                    TextInput::make('width_mm')
                        ->label('العرض')
                        ->suffix('مم')
                        ->numeric()
                        ->required()
                        ->minValue(10)
                        ->maxValue(150)
                        ->default(38)
                        ->live(onBlur: true),
                    TextInput::make('height_mm')
                        ->label('الطول')
                        ->suffix('مم')
                        ->numeric()
                        ->required()
                        ->minValue(5)
                        ->maxValue(200)
                        ->default(25)
                        ->live(onBlur: true),
                    Select::make('columns')
                        ->label('عدد الملصقات في الصف')
                        ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])
                        ->default(1)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live(),
                    TextInput::make('column_gap_mm')
                        ->label('المسافة بين الملصقات في الصف')
                        ->suffix('مم')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(20)
                        ->default(2)
                        ->visible(fn (Get $get): bool => (int) $get('columns') > 1)
                        ->live(onBlur: true),
                    Select::make('dpi')
                        ->label('دقة الطابعة')
                        ->helperText('مكتوبة في مواصفات الطابعة. أغلب طابعات الملصقات 203.')
                        ->options(LabelTemplate::DPI_OPTIONS)
                        ->default(203)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live(),
                    Toggle::make('is_default')
                        ->label('التصميم الافتراضي')
                        ->inline(false),
                ]),

            Grid::make(5)
                ->columnSpanFull()
                ->schema([
                    Section::make('العناصر')
                        ->description('المكان بالمللي من الركن العلوي الشمال للملصق.')
                        ->columnSpan(3)
                        ->schema([
                            Repeater::make('elements')
                                ->hiddenLabel()
                                ->default(LabelTemplate::starterElements())
                                ->collapsible()
                                ->reorderable(false)
                                ->addActionLabel('إضافة عنصر')
                                ->itemLabel(fn (array $state): ?string => LabelElementType::tryFrom((string) ($state['type'] ?? ''))?->label())
                                ->live()
                                ->columns(4)
                                ->schema(self::elementSchema()),
                        ]),
                    Section::make('المعاينة')
                        ->description('بالمقاس الحقيقي على دقة الطابعة، ببيانات صنف تجريبي.')
                        ->columnSpan(2)
                        ->schema([
                            View::make('filament.labels.template-preview')
                                ->viewData(fn (Get $get): array => self::previewData($get)),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function elementSchema(): array
    {
        $mm = fn (string $name, string $label, float $max): TextInput => TextInput::make($name)
            ->label($label)
            ->suffix('مم')
            ->numeric()
            ->step(0.1)
            ->minValue(0)
            ->maxValue($max)
            ->required()
            ->live(onBlur: true);

        $isBarcode = fn (Get $get): bool => $get('type') === LabelElementType::BARCODE->value;

        return [
            Select::make('type')
                ->label('العنصر')
                ->options(LabelElementType::options())
                ->required()
                ->selectablePlaceholder(false)
                ->default(LabelElementType::TEXT->value)
                ->live()
                ->columnSpan(2),
            Select::make('align')
                ->label('المحاذاة')
                ->options(['right' => 'يمين', 'center' => 'وسط', 'left' => 'شمال'])
                ->default('center')
                ->selectablePlaceholder(false)
                ->live(),
            TextInput::make('font_size')
                ->label('حجم الخط')
                ->suffix('pt')
                ->numeric()
                ->step(0.5)
                ->minValue(3)
                ->maxValue(72)
                ->default(7)
                ->hidden($isBarcode)
                ->live(onBlur: true),
            $mm('x', 'من الشمال', 200),
            $mm('y', 'من فوق', 200),
            $mm('width', 'العرض', 200)->default(30),
            $mm('height', 'الارتفاع', 200)->default(4),
            Toggle::make('bold')
                ->label('خط عريض')
                ->hidden($isBarcode)
                ->live(),
            TextInput::make('text')
                ->label('النص')
                ->helperText('ممكن تستخدم {name} {variant} {barcode} {price} {shop}')
                ->maxLength(200)
                ->visible(fn (Get $get): bool => $get('type') === LabelElementType::TEXT->value)
                ->live(onBlur: true)
                ->columnSpan(3),
        ];
    }

    /**
     * @return array{html: string, width: int, height: int}
     */
    public static function previewData(Get $get): array
    {
        $template = new LabelTemplate([
            'width_mm' => max(10, min(150, (float) $get('width_mm') ?: 38)),
            'height_mm' => max(5, min(200, (float) $get('height_mm') ?: 25)),
            'columns' => max(1, min(4, (int) $get('columns') ?: 1)),
            'column_gap_mm' => max(0, (float) $get('column_gap_mm')),
            'dpi' => array_key_exists((int) $get('dpi'), LabelTemplate::DPI_OPTIONS) ? (int) $get('dpi') : 203,
            'elements' => array_values(array_filter((array) $get('elements'), 'is_array')),
        ]);

        return [
            'html' => app(LabelRenderer::class)->rowHtml($template, array_fill(0, $template->columns, LabelData::sample()), outline: true),
            'width' => $template->dots($template->rowWidthMm()),
            'height' => $template->dots($template->height_mm),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('التصميم')->searchable(),
                TextColumn::make('size')
                    ->label('المقاس')
                    ->state(fn (LabelTemplate $record): string => $record->sizeLabel()),
                TextColumn::make('dpi')->label('الدقة')->suffix(' DPI'),
                IconColumn::make('is_default')->label('افتراضي')->boolean(),
            ])
            ->recordActions([
                Action::make('test_print')
                    ->label('تجربة طباعة')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('print_labels'))
                    ->action(function (LabelTemplate $record, Component $livewire): void {
                        $job = app(LabelPrintJobs::class)->sample($record);
                        $url = route('labels.jobs.browser', ['job' => $job]);
                        $livewire->js('window.open('.json_encode($url).', "_blank")');
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabelTemplates::route('/'),
            'create' => CreateLabelTemplate::route('/create'),
            'edit' => EditLabelTemplate::route('/{record}/edit'),
        ];
    }
}
