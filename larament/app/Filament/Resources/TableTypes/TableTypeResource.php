<?php

namespace App\Filament\Resources\TableTypes;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\TableTypes\Pages\ListTableTypes;
use App\Filament\Resources\TableTypes\Pages\CreateTableType;
use App\Filament\Resources\TableTypes\Pages\EditTableType;
use App\Filament\Resources\TableTypeResource\Pages;
use App\Filament\Resources\TableTypeResource\RelationManagers;
use App\Filament\Traits\AdminAccess;
use App\Models\TableType;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TableTypeResource extends Resource
{
    use AdminAccess;
    protected static ?string $model = TableType::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'أنواع الطاولات';

    protected static ?string $modelLabel = 'نوع طاولة';

    protected static ?string $pluralModelLabel = 'أنواع الطاولات';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة المطعم';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات نوع الطاولة')
                    ->schema([
                        TextInput::make('name')
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
                TextColumn::make('id')
                    ->label('الرقم')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('اسم نوع الطاولة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('تاريخ التحديث')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->label('تعديل'),
                DeleteAction::make()
                    ->label('حذف'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
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
            'index' => ListTableTypes::route('/'),
            'create' => CreateTableType::route('/create'),
            'edit' => EditTableType::route('/{record}/edit'),
        ];
    }
}
