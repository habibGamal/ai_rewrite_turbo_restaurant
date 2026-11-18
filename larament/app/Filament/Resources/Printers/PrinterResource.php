<?php

namespace App\Filament\Resources\Printers;

use App\Enums\ProductType;
use App\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Filament\Resources\Printers\Pages\EditPrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Filament\Resources\Printers\Pages\ViewPrinter;
use App\Models\Category;
use App\Models\Printer;
use App\Models\Product;
use App\PrintAgent\PrintAgentQueue;
use App\Services\PrinterScanService;
use App\Services\PrintService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'طابعة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطابعات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم الطابعة')
                    ->required()
                    ->maxLength(255),

                Section::make('إعدادات الاتصال والطباعة')
                    ->columnSpanFull()
                    ->schema([
                        ToggleButtons::make('print_via')
                            ->label('الطباعة عن طريق')
                            ->options([
                                Printer::PRINT_VIA_SERVER => 'الخادم (IP أو طابعة مشتركة)',
                                Printer::PRINT_VIA_AGENT => 'برنامج Turbo Label',
                            ])
                            ->helperText('إذا كان النظام على خادم بعيد عن المطعم (VPS)، فاختر برنامج Turbo Label ليطبع طلبات المطبخ عبر الجهاز المتصل بالطابعة.')
                            ->default(Printer::PRINT_VIA_SERVER)
                            ->inline()
                            ->live()
                            ->required(),

                        Grid::make(2)
                            ->visible(fn (Get $get): bool => $get('print_via') !== Printer::PRINT_VIA_AGENT)
                            ->schema([
                                TextInput::make('ip_address')
                                    ->label('عنوان IP')
                                    ->helperText(
                                        'أدخل عنوان IP بصيغة صحيحة أو //ip/printerName للطابعة المشتركة عبر USB'
                                    )
                                    ->maxLength(255),

                                Actions::make([
                                    Action::make('scan_printers')
                                        ->label('البحث عن الطابعات')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->color('info')
                                        ->modalHeading('البحث عن الطابعات في الشبكة')
                                        ->modalDescription('البحث عن الطابعات المتاحة في الشبكة المحلية')
                                        ->modalSubmitActionLabel('تحديد الطابعة')
                                        ->modalCancelActionLabel('إغلاق')
                                        ->steps([
                                            Step::make('network_scan')
                                                ->label('البحث في الشبكة')
                                                ->description('أدخل نطاق الشبكة وابدأ البحث')
                                                ->schema([
                                                    TextInput::make('network_range')
                                                        ->label('نطاق الشبكة')
                                                        ->default('192.168.1.0/24')
                                                        ->required()
                                                        ->helperText('أدخل نطاق الشبكة للبحث (مثال: 192.168.1.0/24)'),

                                                    Actions::make([
                                                        Action::make('start_scan')
                                                            ->label('بدء البحث')
                                                            ->icon('heroicon-o-magnifying-glass')
                                                            ->color('primary')
                                                            ->action(function (array $data, $livewire, $set, $get) {
                                                                $scanService = app(PrinterScanService::class);

                                                                if (! $scanService->isNmapAvailable()) {
                                                                    Notification::make()
                                                                        ->title('خطأ')
                                                                        ->body('برنامج nmap غير متوفر على النظام')
                                                                        ->danger()
                                                                        ->send();

                                                                    return;
                                                                }

                                                                try {
                                                                    $printers = $scanService->scanNetworkForPrinters($data['network_range'] ?? '192.168.1.0/24');

                                                                    $set('scan_results', $printers);

                                                                    Notification::make()
                                                                        ->title('تم البحث بنجاح')
                                                                        ->body('تم العثور على '.count($printers).' طابعة محتملة')
                                                                        ->success()
                                                                        ->send();
                                                                } catch (Exception $e) {
                                                                    Notification::make()
                                                                        ->title('خطأ في البحث')
                                                                        ->body('حدث خطأ أثناء البحث: '.$e->getMessage())
                                                                        ->danger()
                                                                        ->send();
                                                                }
                                                            }),
                                                    ]),
                                                ]),

                                            Step::make('printer_selection')
                                                ->label('اختيار الطابعة')
                                                ->description('اختبر واختر الطابعة المناسبة')
                                                ->schema([
                                                    Repeater::make('scan_results')
                                                        ->label('الطابعات المكتشفة')
                                                        ->defaultItems(0)
                                                        ->schema([
                                                            TextInput::make('ip')
                                                                ->label('عنوان IP للطابعة')
                                                                ->required()
                                                                ->maxLength(255)
                                                                ->suffixAction(
                                                                    Action::make('test_connection')
                                                                        ->label('اختبار الاتصال')
                                                                        ->icon('heroicon-o-link')
                                                                        ->action(function ($state, $livewire, $set) {
                                                                            $scanService = app(PrinterScanService::class);
                                                                            try {
                                                                                $result = $scanService->testPrinter($state);
                                                                                $set('status', 'متصل');
                                                                                Notification::make()
                                                                                    ->title('نجاح')
                                                                                    ->body('تم الاتصال بالطابعة بنجاح: '.$state)
                                                                                    ->success()
                                                                                    ->send();
                                                                            } catch (Exception $e) {
                                                                                $set('status', 'غير متصل');
                                                                                Notification::make()
                                                                                    ->title('فشل الاتصال')
                                                                                    ->body('تعذر الاتصال بالطابعة: '.$e->getMessage())
                                                                                    ->danger()
                                                                                    ->send();
                                                                            }
                                                                        })
                                                                ),
                                                            TextInput::make('status')
                                                                ->label('الحالة')
                                                                ->disabled(),
                                                            Actions::make([
                                                                Action::make('test_connection')
                                                                    ->label('اختبار الاتصال')
                                                                    ->icon('heroicon-o-link')
                                                                    ->action(function ($state, $livewire, $set) {
                                                                        $scanService = app(PrinterScanService::class);
                                                                        try {
                                                                            $result = $scanService->testPrinter($state['ip']);
                                                                            $set('status', 'متصل');
                                                                            Notification::make()
                                                                                ->title('نجاح')
                                                                                ->body('تم الاتصال بالطابعة بنجاح: '.$state['ip'])
                                                                                ->success()
                                                                                ->send();
                                                                        } catch (Exception $e) {
                                                                            $set('status', 'غير متصل');
                                                                            Notification::make()
                                                                                ->title('فشل الاتصال')
                                                                                ->body('تعذر الاتصال بالطابعة: '.$e->getMessage())
                                                                                ->danger()
                                                                                ->send();
                                                                        }
                                                                    }),
                                                                Action::make('select')
                                                                    ->label('تحديد هذه الطابعة')
                                                                    ->icon('heroicon-o-check')
                                                                    ->action(function (array $state, $data, $get, $set) {
                                                                        if (! empty($state['ip'])) {
                                                                            $set('../../selected_ip', $state['ip']);
                                                                            Notification::make()
                                                                                ->title('تم التحديد')
                                                                                ->body('تم تحديد الطابعة: '.$state['ip'])
                                                                                ->success()
                                                                                ->send();
                                                                        } else {
                                                                            Notification::make()
                                                                                ->title('لم يتم التحديد')
                                                                                ->body('يرجى تحديد طابعة قبل المتابعة')
                                                                                ->warning()
                                                                                ->send();
                                                                        }
                                                                    }),
                                                            ]),
                                                        ])
                                                        ->columnSpanFull()
                                                        ->columns(4)
                                                        ->disableItemMovement()
                                                        ->disableItemDeletion()
                                                        ->disableItemCreation(),

                                                    Hidden::make('selected_ip')
                                                        ->reactive(),
                                                ]),
                                        ])
                                        ->action(function (array $data, $livewire, $set) {
                                            // Set the selected IP to the main form's ip_address field
                                            if (! empty($data['selected_ip'])) {
                                                $set('ip_address', $data['selected_ip']);

                                                Notification::make()
                                                    ->title('تم التحديد')
                                                    ->body('تم تحديد الطابعة: '.$data['selected_ip'])
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('لم يتم التحديد')
                                                    ->body('يرجى تحديد طابعة قبل المتابعة')
                                                    ->warning()
                                                    ->send();
                                            }
                                        }),
                                ])
                                    ->alignEnd(),
                            ]),

                        Select::make('agent_printer')
                            ->label('الطابعة على الجهاز')
                            ->helperText('من الطابعات التي عثر عليها البرنامج في الجهاز المربوط. إذا تُركت فارغة تُستخدم طابعة الفواتير المختارة في البرنامج نفسه.')
                            ->options(fn (?Printer $record): array => collect($record?->agentPrinters() ?? [])
                                ->when(filled($record?->agent_printer), fn ($c) => $c->push($record->agent_printer))
                                ->unique()
                                ->mapWithKeys(fn (string $name): array => [$name => $name])
                                ->all())
                            ->placeholder('طابعة البرنامج')
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('print_via') === Printer::PRINT_VIA_AGENT),

                        Text::make(fn (?Printer $record): string => self::agentStatus($record))
                            ->visible(fn (Get $get): bool => $get('print_via') === Printer::PRINT_VIA_AGENT),
                    ]),

                CheckboxList::make('categories')
                    ->label('اختر بالفئات ')
                    ->options(
                        Category::all()->pluck('name', 'id')
                    )
                    ->afterStateUpdated(function (array $state, callable $set) {
                        $set(
                            'products',
                            Product::whereIn('category_id', $state)
                                ->whereIn('type', [ProductType::Consumable, ProductType::Manufactured])
                                ->with('category')
                                ->orderBy('category_id')
                                ->pluck('id')
                                ->toArray()
                        );
                    })
                    ->bulkToggleable()
                    ->reactive()
                    ->dehydrated(false)
                    ->columns(3),

                CheckboxList::make('products')
                    ->label('المنتجات المرتبطة')
                    ->relationship(
                        'products',
                        'name',
                        function ($query) {
                            return $query->whereIn('type', [ProductType::Consumable, ProductType::Manufactured])
                                ->with('category')
                                ->orderBy('category_id');
                        }
                    )
                    ->getOptionLabelFromRecordUsing(fn (Product $product) => "$product->name ({$product->category?->name})")
                    ->bulkToggleable()
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الطابعة')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('عنوان IP')
                    ->state(fn (Printer $record): ?string => $record->printsViaAgent()
                        ? 'Turbo Label'.($record->agentPrinter() !== null ? ' · '.$record->agentPrinter() : '')
                        : $record->ipAddress())
                    ->description(fn (Printer $record): ?string => $record->printsViaAgent() ? self::agentStatus($record) : null)
                    ->placeholder('غير محدد')
                    ->sortable(['ip_address']),
                TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->counts('products')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('pairAgent')
                    ->label(fn (Printer $record): string => $record->agentPaired() ? 'رمز ربط جديد للبرنامج' : 'ربط برنامج الطباعة')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->visible(fn (Printer $record): bool => $record->printsViaAgent() && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('ربط برنامج Turbo Label بالطابعة')
                    ->modalDescription(fn (Printer $record): string => $record->agentPaired()
                        ? 'سيُلغي الرمز الجديد ربط البرنامج الحالي، ويجب ربطه من جديد.'
                        : 'افتح هذه الصفحة من الجهاز المتصل بالطابعة، وبعد ظهور الرمز اضغط «ربط البرنامج» أو انسخ الرمز في نافذة البرنامج.')
                    ->modalSubmitActionLabel('إنشاء رمز الربط')
                    ->action(function (Printer $record): void {
                        $code = app(PrintAgentQueue::class)->pair($record);

                        Notification::make()
                            ->success()
                            ->persistent()
                            ->title('رمز ربط الطابعة «'.$record->name.'»')
                            ->body(new HtmlString(
                                '<p>على الجهاز المتصل بالطابعة، اضغط «ربط البرنامج»، أو افتح Turbo Label والصق هذا الرمز:</p>'
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
                    ->visible(fn (Printer $record): bool => $record->printsViaAgent() && $record->agentPaired())
                    ->action(function (Printer $record): void {
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
                    ->visible(fn (Printer $record): bool => $record->agentPaired() && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->action(function (Printer $record): void {
                        app(PrintAgentQueue::class)->unpair($record);
                        Notification::make()->success()->title('تم إلغاء ربط البرنامج')->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function agentStatus(?Printer $record): string
    {
        if ($record === null || ! $record->agentPaired()) {
            return 'البرنامج غير مربوط. احفظ الطابعة ثم اضغط «ربط برنامج الطباعة» من قائمة الطابعات.';
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
            'index' => ListPrinters::route('/'),
            'create' => CreatePrinter::route('/create'),
            'view' => ViewPrinter::route('/{record}'),
            'edit' => EditPrinter::route('/{record}/edit'),
        ];
    }
}
