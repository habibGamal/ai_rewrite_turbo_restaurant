<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RawMaterialProductResource\Pages;
use App\Models\Product;
use App\Models\Category;
use App\Models\Printer;
use App\Enums\ProductType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Traits\AdminAccess;

class RawMaterialProductResource extends Resource
{
    use AdminAccess;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'إدارة المنتجات';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'مادة خام';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المواد الخام';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', ProductType::RawMaterial->value);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('اسم المادة الخام')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('cost')
                    ->label('التكلفة')
                    ->required()
                    ->numeric()
                    ->prefix('ج.م'),
                Forms\Components\TextInput::make('min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Select::make('unit')
                    ->label('الوحدة')
                    ->options([
                        'packet' => 'حزمة',
                        'kg' => 'كيلوجرام',
                    ])
                    ->required(),
                Forms\Components\Hidden::make('type')
                    ->default('raw_material'),
                Forms\Components\Toggle::make('legacy')
                    ->label('مادة قديمة')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المادة الخام')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('الفئة')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('cost')
                    ->label('التكلفة')
                    ->money('EGP')
                    ->sortable(),
                Tables\Columns\TextColumn::make('min_stock')
                    ->label('الحد الأدنى للمخزون')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit')
                    ->label('الوحدة')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'packet' => 'حزمة',
                        'kg' => 'كيلوجرام',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('inventoryItem.quantity')
                    ->label('المخزون')
                    ->sortable()
                    ->default('0'),
                Tables\Columns\IconColumn::make('legacy')
                    ->label('مادة قديمة')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('الفئة')
                    ->options(Category::all()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('printer_id')
                    ->label('الطابعة')
                    ->options(Printer::all()->pluck('name', 'id')),
                Tables\Filters\TernaryFilter::make('legacy')
                    ->label('مادة قديمة'),
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
            'index' => Pages\ListRawMaterialProducts::route('/'),
            'create' => Pages\CreateRawMaterialProduct::route('/create'),
            'view' => Pages\ViewRawMaterialProduct::route('/{record}'),
            'edit' => Pages\EditRawMaterialProduct::route('/{record}/edit'),
        ];
    }
}
