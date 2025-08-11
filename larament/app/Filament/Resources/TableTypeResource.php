<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TableTypeResource\Pages;
use App\Filament\Resources\TableTypeResource\RelationManagers;
use App\Models\TableType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TableTypeResource extends Resource
{
    protected static ?string $model = TableType::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'أنواع الطاولات';

    protected static ?string $modelLabel = 'نوع طاولة';

    protected static ?string $pluralModelLabel = 'أنواع الطاولات';

    protected static ?string $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات نوع الطاولة')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم نوع الطاولة')
                            ->required()
                            ->maxLength(255)
                            ->unique(TableType::class, 'name', ignoreRecord: true)
                            ->placeholder('مثال: VIP، كلاسيك، بدوي')
                            ->helperText('أدخل اسم نوع الطاولة (يجب أن يكون فريداً)')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('الرقم')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم نوع الطاولة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('تعديل'),
                Tables\Actions\DeleteAction::make()
                    ->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateHeading('لا توجد أنواع طاولات')
            ->emptyStateDescription('ابدأ بإنشاء نوع طاولة جديد')
            ->emptyStateIcon('heroicon-o-table-cells');
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
            'index' => Pages\ListTableTypes::route('/'),
            'create' => Pages\CreateTableType::route('/create'),
            'edit' => Pages\EditTableType::route('/{record}/edit'),
        ];
    }
}
