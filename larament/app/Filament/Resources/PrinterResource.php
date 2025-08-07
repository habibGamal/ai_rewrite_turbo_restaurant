<?php

namespace App\Filament\Resources;

use App\Enums\ProductType;
use App\Filament\Resources\PrinterResource\Pages;
use App\Models\Printer;
use App\Models\Product;
use App\Services\PrinterScanService;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use \App\Filament\Traits\AdminAccess;

class PrinterResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Printer::class;

    protected static ?string $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return 'طابعة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطابعات';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم الطابعة')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\TextInput::make('ip_address')
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
                                            Forms\Components\TextInput::make('network_range')
                                                ->label('نطاق الشبكة')
                                                ->default('192.168.1.0/24')
                                                ->required()
                                                ->helperText('أدخل نطاق الشبكة للبحث (مثال: 192.168.1.0/24)'),

                                            Forms\Components\Actions::make([
                                                Action::make('start_scan')
                                                    ->label('بدء البحث')
                                                    ->icon('heroicon-o-magnifying-glass')
                                                    ->color('primary')
                                                    ->action(function (array $data, $livewire, $set, $get) {
                                                        $scanService = app(PrinterScanService::class);

                                                        if (!$scanService->isNmapAvailable()) {
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
                                                                ->body('تم العثور على ' . count($printers) . ' طابعة محتملة')
                                                                ->success()
                                                                ->send();
                                                        } catch (\Exception $e) {
                                                            Notification::make()
                                                                ->title('خطأ في البحث')
                                                                ->body('حدث خطأ أثناء البحث: ' . $e->getMessage())
                                                                ->danger()
                                                                ->send();
                                                        }
                                                    })
                                            ])
                                        ]),

                                    Step::make('printer_selection')
                                        ->label('اختيار الطابعة')
                                        ->description('اختبر واختر الطابعة المناسبة')
                                        ->schema([
                                            Forms\Components\Repeater::make('scan_results')
                                                ->label('الطابعات المكتشفة')
                                                ->defaultItems(0)
                                                ->schema([
                                                    Forms\Components\TextInput::make('ip')
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
                                                                            ->body('تم الاتصال بالطابعة بنجاح: ' . $state)
                                                                            ->success()
                                                                            ->send();
                                                                    } catch (\Exception $e) {
                                                                        $set('status', 'غير متصل');
                                                                        Notification::make()
                                                                            ->title('فشل الاتصال')
                                                                            ->body('تعذر الاتصال بالطابعة: ' . $e->getMessage())
                                                                            ->danger()
                                                                            ->send();
                                                                    }
                                                                })
                                                        ),
                                                    Forms\Components\TextInput::make('status')
                                                        ->label('الحالة')
                                                        ->disabled(),
                                                    Forms\Components\Actions::make([
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
                                                                        ->body('تم الاتصال بالطابعة بنجاح: ' . $state['ip'])
                                                                        ->success()
                                                                        ->send();
                                                                } catch (\Exception $e) {
                                                                    $set('status', 'غير متصل');
                                                                    Notification::make()
                                                                        ->title('فشل الاتصال')
                                                                        ->body('تعذر الاتصال بالطابعة: ' . $e->getMessage())
                                                                        ->danger()
                                                                        ->send();
                                                                }
                                                            }),
                                                        Action::make('select')
                                                            ->label('تحديد هذه الطابعة')
                                                            ->icon('heroicon-o-check')
                                                            ->action(function (array $state, $data,$get, $set) {
                                                                if (!empty($state['ip'])) {
                                                                    $set('../../selected_ip', $state['ip']);
                                                                    Notification::make()
                                                                        ->title('تم التحديد')
                                                                        ->body('تم تحديد الطابعة: ' . $state['ip'])
                                                                        ->success()
                                                                        ->send();
                                                                } else {
                                                                    Notification::make()
                                                                        ->title('لم يتم التحديد')
                                                                        ->body('يرجى تحديد طابعة قبل المتابعة')
                                                                        ->warning()
                                                                        ->send();
                                                                }
                                                            })
                                                    ])
                                                ])
                                                ->columnSpanFull()
                                                ->columns(4)
                                                ->disableItemMovement()
                                                ->disableItemDeletion()
                                                ->disableItemCreation(),

                                            Forms\Components\Hidden::make('selected_ip')
                                                ->reactive()
                                        ])
                                ])
                                ->action(function (array $data, $livewire, $set) {
                                    // Set the selected IP to the main form's ip_address field
                                    if (!empty($data['selected_ip'])) {
                                        $set('ip_address', $data['selected_ip']);

                                        Notification::make()
                                            ->title('تم التحديد')
                                            ->body('تم تحديد الطابعة: ' . $data['selected_ip'])
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('لم يتم التحديد')
                                            ->body('يرجى تحديد طابعة قبل المتابعة')
                                            ->warning()
                                            ->send();
                                    }
                                })
                        ])
                            ->alignEnd()
                    ]),

                CheckboxList::make('categories')
                    ->label('اختر بالفئات ')
                    ->options(
                        \App\Models\Category::all()->pluck('name', 'id')
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
                    ->getOptionLabelFromRecordUsing(fn(Product $product) => "$product->name ({$product->category?->name})")
                    ->bulkToggleable()
                    ->columns(3)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الطابعة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('عنوان IP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label('عدد المنتجات')
                    ->counts('products')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrinters::route('/'),
            'create' => Pages\CreatePrinter::route('/create'),
            'view' => Pages\ViewPrinter::route('/{record}'),
            'edit' => Pages\EditPrinter::route('/{record}/edit'),
        ];
    }
}
