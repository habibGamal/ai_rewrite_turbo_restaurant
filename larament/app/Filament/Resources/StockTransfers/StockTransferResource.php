<?php

namespace App\Filament\Resources\StockTransfers;

use App\Enums\ProductType;
use App\Filament\Actions\ConfirmStockTransferAction;
use App\Filament\Actions\PrintLabelsAction;
use App\Filament\Components\Forms\DocumentBarcodeField;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'أذون التحويل';

    protected static ?string $modelLabel = 'إذن تحويل';

    protected static ?string $pluralModelLabel = 'أذون التحويل';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المخزون';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات التحويل')->columns(2)->schema([
                Select::make('from_warehouse_id')->label('من مخزن')->required()->searchable()
                    ->options(fn () => Warehouse::active()->pluck('name', 'id')->all())
                    ->default(fn () => Warehouse::default()->id),
                Select::make('to_warehouse_id')->label('إلى مخزن')->required()->searchable()
                    ->different('from_warehouse_id')
                    ->options(fn () => Warehouse::active()->pluck('name', 'id')->all()),
                Select::make('user_id')->label('المستخدم')->required()->searchable()
                    ->options(fn () => User::query()->pluck('name', 'id')->all())->default(fn () => auth()->id()),
                TextInput::make('total_cost')->label('إجمالي التكلفة (ج.م)')->disabled()->dehydrated(false)->default(0),
                Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
            ]),
            Section::make('أصناف التحويل')->schema([
                Repeater::make('items')->label('الأصناف')->relationship()->minItems(1)->defaultItems(1)
                    ->disabled(fn (?StockTransfer $record): bool => $record?->closed_at !== null)
                    ->columns(fn (): int => DocumentBarcodeField::enabled() ? 3 : 2)->schema([
                        Select::make('product_id')->label('المنتج')->required()->searchable()
                            ->options(fn () => Product::whereIn('type', [ProductType::RawMaterial, ProductType::Consumable])->pluck('name', 'id')->all())
                            ->rules([Rule::exists('products', 'id')->whereIn('type', [ProductType::RawMaterial->value, ProductType::Consumable->value])])
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => DocumentBarcodeField::fill($set, $state)),
                        ...DocumentBarcodeField::whenEnabled(),
                        TextInput::make('quantity')->label('الكمية')->required()->numeric()->step(0.001)->minValue(0.001)->default(1),
                    ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات التحويل')->columns(3)->schema([
                TextEntry::make('fromWarehouse.name')->label('من مخزن'),
                TextEntry::make('toWarehouse.name')->label('إلى مخزن'),
                TextEntry::make('user.name')->label('المستخدم'),
                TextEntry::make('total_cost')->label('إجمالي التكلفة')->money('EGP'),
                TextEntry::make('closed_at')->label('تاريخ التأكيد')->dateTime()->placeholder('مسودة'),
                TextEntry::make('closedByUser.name')->label('أكد بواسطة'),
                TextEntry::make('business_date')->label('يوم العمل')->date(),
                TextEntry::make('notes')->label('ملاحظات')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('رقم التحويل')->sortable(),
            TextColumn::make('fromWarehouse.name')->label('من مخزن')->searchable(),
            TextColumn::make('toWarehouse.name')->label('إلى مخزن')->searchable(),
            TextColumn::make('user.name')->label('المستخدم'),
            TextColumn::make('total_cost')->label('إجمالي التكلفة')->money('EGP')->sortable(),
            TextColumn::make('closed_at')->label('الحالة')->placeholder('مسودة')
                ->formatStateUsing(fn ($state): string => $state ? 'مؤكد' : 'مسودة')->badge(),
            TextColumn::make('business_date')->label('يوم العمل')->date()->sortable(),
        ])->filters([
            SelectFilter::make('from_warehouse_id')->label('من مخزن')->relationship('fromWarehouse', 'name'),
            SelectFilter::make('to_warehouse_id')->label('إلى مخزن')->relationship('toWarehouse', 'name'),
        ])->recordActions([
            ConfirmStockTransferAction::table(), PrintLabelsAction::document(), ViewAction::make(), EditAction::make(), DeleteAction::make(),
        ])->defaultSort('id', 'desc');
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockTransfer && $record->closed_at === null && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return ($record instanceof StockTransfer && $record->closed_at === null && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
