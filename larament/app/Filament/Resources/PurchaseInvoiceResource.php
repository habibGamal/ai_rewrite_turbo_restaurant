<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseInvoiceResource\Pages;
use App\Filament\Resources\PurchaseInvoiceResource\RelationManagers;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\User;
use App\Models\Category;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PurchaseInvoiceResource extends Resource
{
    protected static ?string $model = PurchaseInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'فواتير الشراء';

    protected static ?string $modelLabel = 'فاتورة شراء';

    protected static ?string $pluralModelLabel = 'فواتير الشراء';

    protected static ?string $navigationGroup = 'المشتريات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('معلومات الفاتورة')
                    ->schema([
                        Select::make('user_id')
                            ->label('المستخدم')
                            ->options(User::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->default(auth()->id()),

                        Select::make('supplier_id')
                            ->label('المورد')
                            ->options(Supplier::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('اسم المورد')
                                    ->required(),
                                TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->tel(),
                                TextInput::make('address')
                                    ->label('العنوان'),
                            ]),

                        TextInput::make('total')
                            ->label('إجمالي الفاتورة (ج.م)')
                            ->numeric()
                            ->prefix('ج.م')
                            ->readOnly()
                            ->default(0),
                    ])
                    ->columns(3),

                Section::make('أصناف الفاتورة')
                    ->schema([
                        Actions::make([
                            Action::make('importProducts')
                                ->label('استيراد المنتجات')
                                ->icon('heroicon-m-plus-circle')
                                ->color('success')
                                ->form([
                                    Grid::make(2)
                                        ->schema([
                                            Select::make('category_filter')
                                                ->label('فلترة حسب الفئة')
                                                ->placeholder('جميع الفئات')
                                                ->options(Category::all()->pluck('name', 'id'))
                                                ->reactive()
                                                ->afterStateUpdated(fn($state, callable $set) => $set('selected_products', [])),
                                            
                                            TextInput::make('search_filter')
                                                ->label('البحث في المنتجات')
                                                ->placeholder('ابحث باسم المنتج...')
                                                ->reactive()
                                                ->afterStateUpdated(fn($state, callable $set) => $set('selected_products', [])),
                                        ]),
                                    
                                    CheckboxList::make('selected_products')
                                        ->label('اختر المنتجات للاستيراد')
                                        ->options(function (Get $get) {
                                            $query = Product::query();
                                            
                                            // Filter by category if selected
                                            if ($categoryId = $get('category_filter')) {
                                                $query->where('category_id', $categoryId);
                                            }
                                            
                                            // Filter by search term if provided
                                            if ($search = $get('search_filter')) {
                                                $query->where('name', 'like', '%' . $search . '%');
                                            }
                                            
                                            return $query->get()->mapWithKeys(function ($product) {
                                                $price = $product->cost ?? $product->price;
                                                $categoryName = $product->category ? $product->category->name : 'بدون فئة';
                                                return [
                                                    $product->id => $product->name . ' - ' . $price . ' ج.م' . ' (' . $categoryName . ')'
                                                ];
                                            });
                                        })
                                        ->searchable()
                                        ->bulkToggleable()
                                        ->columns(1)
                                        ->required()
                                        ->reactive()
                                        ->descriptions(function (Get $get) {
                                            $products = Product::query();
                                            
                                            if ($categoryId = $get('category_filter')) {
                                                $products->where('category_id', $categoryId);
                                            }
                                            
                                            if ($search = $get('search_filter')) {
                                                $products->where('name', 'like', '%' . $search . '%');
                                            }
                                            
                                            return $products->get()->mapWithKeys(function ($product) {
                                                $cost = $product->cost ?? 0;
                                                $price = $product->price ?? 0;
                                                $description = "سعر التكلفة: {$cost} ج.م | سعر البيع: {$price} ج.م";
                                                if ($product->unit) {
                                                    $description .= " | الوحدة: {$product->unit}";
                                                }
                                                return [$product->id => $description];
                                            });
                                        })
                                ])
                                ->action(function (array $data, Set $set, Get $get) {
                                    $selectedProducts = $data['selected_products'] ?? [];
                                    $currentItems = $get('items') ?? [];
                                    
                                    // Get existing product IDs to avoid duplicates
                                    $existingProductIds = collect($currentItems)->pluck('product_id')->filter()->toArray();
                                    
                                    $addedCount = 0;
                                    $skippedCount = 0;
                                    
                                    foreach ($selectedProducts as $productId) {
                                        // Skip if product already exists in the list
                                        if (in_array($productId, $existingProductIds)) {
                                            $skippedCount++;
                                            continue;
                                        }
                                        
                                        $product = Product::find($productId);
                                        if ($product) {
                                            $price = $product->cost ?? $product->price;
                                            $quantity = 1;
                                            $total = $quantity * $price;
                                            
                                            $currentItems[] = [
                                                'product_id' => $product->id,
                                                'quantity' => $quantity,
                                                'price' => $price,
                                                'total' => $total,
                                            ];
                                            $addedCount++;
                                        }
                                    }
                                    
                                    $set('items', $currentItems);
                                    
                                    // Recalculate invoice total
                                    $invoiceTotal = 0;
                                    foreach ($currentItems as $item) {
                                        $invoiceTotal += $item['total'] ?? 0;
                                    }
                                    $set('total', $invoiceTotal);
                                    
                                    // Show notification
                                    $message = "تم إضافة {$addedCount} منتج بنجاح";
                                    if ($skippedCount > 0) {
                                        $message .= " وتم تجاهل {$skippedCount} منتج موجود مسبقاً";
                                    }
                                    
                                    \Filament\Notifications\Notification::make()
                                        ->title('تم استيراد المنتجات')
                                        ->body($message)
                                        ->success()
                                        ->send();
                                })
                                ->modalHeading('استيراد المنتجات')
                                ->modalSubheading('اختر المنتجات التي تريد إضافتها إلى الفاتورة. يمكنك استخدام الفلاتر لتسهيل البحث.')
                                ->modalWidth('2xl')
                                ->slideOver()
                        ])
                        ->alignStart(),
                        
                        TableRepeater::make('items')
                            ->label('الأصناف')
                            ->relationship('items')
                            ->headers([
                                Header::make('product_id')->label('المنتج')->width('200px'),
                                Header::make('quantity')->label('الكمية')->width('100px'),
                                Header::make('price')->label('سعر الوحدة (ج.م)')->width('120px'),
                                Header::make('total')->label('الإجمالي (ج.م)')->width('120px'),
                            ])
                            ->schema([
                                Select::make('product_id')
                                    ->label('المنتج')
                                    ->relationship('product', 'name')
                                    ->searchable()
                                    ->required(),

                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(1)
                                    ->reactive()
                                    ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                        $price = $get('price') ?? 0;
                                        $set('total', ($state ?? 1) * $price);
                                    }),

                                TextInput::make('price')
                                    ->label('سعر الوحدة (ج.م)')
                                    ->numeric()
                                    ->required()
                                    ->prefix('ج.م')
                                    ->reactive()
                                    ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                        $quantity = $get('quantity') ?? 1;
                                        $set('total', $quantity * ($state ?? 0));
                                    }),

                                TextInput::make('total')
                                    ->label('الإجمالي (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->readOnly(),
                            ])
                            ->columns(4)
                            ->defaultItems(1)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->reactive()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $items = $get('items') ?? [];
                                $total = 0;
                                foreach ($items as $item) {
                                    $total += $item['total'] ?? 0;
                                }
                                $set('total', $total);
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('رقم الفاتورة')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('supplier.name')
                    ->label('المورد')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('المستخدم')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('items_count')
                    ->label('عدد الأصناف')
                    ->counts('items')
                    ->sortable(),

                TextColumn::make('total')
                    ->label('إجمالي الفاتورة')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('المورد')
                    ->relationship('supplier', 'name'),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('المستخدم')
                    ->relationship('user', 'name'),

                Tables\Filters\Filter::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('من تاريخ'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('إلى تاريخ'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn(Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
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
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseInvoices::route('/'),
            'create' => Pages\CreatePurchaseInvoice::route('/create'),
            'edit' => Pages\EditPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
