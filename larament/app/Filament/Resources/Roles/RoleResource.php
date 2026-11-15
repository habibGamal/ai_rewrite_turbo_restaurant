<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Orders\TabPermissions;
use App\Services\FeatureService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return 'دور';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الأدوار والصلاحيات';
    }

    public static function visiblePermissionsCatalog(): array
    {
        return app(FeatureService::class)->filterPermissionCatalog(
            RolesAndPermissionsSeeder::permissionsCatalog()
        );
    }

    public static function form(Schema $schema): Schema
    {
        $catalog = self::visiblePermissionsCatalog();

        return $schema
            ->components([
                Section::make('معلومات الدور')
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الدور')
                            ->placeholder('مثال: كاشير مسائي، مدير مشتريات...')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                    ]),

                Tabs::make('الصلاحيات')
                    ->tabs([
                        Tab::make('إدارة الموارد والمطعم')
                            ->icon('heroicon-o-building-storefront')
                            ->schema([
                                Section::make('المنتجات والفئات والطاولات')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_catalog')
                                            ->hiddenLabel()
                                            ->options($catalog['إدارة المنتجات والقوائم'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                                Section::make('المشتريات والمخزون')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_purchasing')
                                            ->hiddenLabel()
                                            ->options($catalog['المشتريات والمخزون'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                                Section::make('العملاء والسائقين والمصروفات')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_customers')
                                            ->hiddenLabel()
                                            ->options($catalog['العملاء والسائقين والمصروفات'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                            ]),

                        Tab::make('التقارير وإدارة النظام')
                            ->icon('heroicon-o-chart-bar')
                            ->schema([
                                Section::make('إدارة النظام')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_system')
                                            ->hiddenLabel()
                                            ->options($catalog['إدارة النظام'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                                Section::make('التقارير')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_reports')
                                            ->hiddenLabel()
                                            ->options($catalog['التقارير'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                            ]),

                        Tab::make('شاشة البيع والكاشير والمطبخ')
                            ->icon('heroicon-o-shopping-cart')
                            ->schema([
                                Section::make('نقطة البيع والعمليات (POS)')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_pos')
                                            ->hiddenLabel()
                                            ->options($catalog['نقطة البيع والعمليات (POS)'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                                Section::make('شاشة المطبخ')
                                    ->collapsible()
                                    ->schema([
                                        CheckboxList::make('permissions_kitchen')
                                            ->hiddenLabel()
                                            ->options($catalog['شاشة المطبخ'] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                            ]),

                        Tab::make(TabPermissions::GROUP)
                            ->icon('heroicon-o-squares-2x2')
                            ->schema([
                                Section::make('التبويبات التي يراها هذا الدور في شاشة الكاشير')
                                    ->description('يتم إضافة أنواع الطلبات الجديدة هنا تلقائيًا. يحتاج الدور أيضًا إلى صلاحية الدخول لنقطة البيع.')
                                    ->schema([
                                        CheckboxList::make('permissions_pos_tabs')
                                            ->hiddenLabel()
                                            ->options($catalog[TabPermissions::GROUP] ?? [])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->dehydrated(false),
                                    ]),
                            ]),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الدور')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'مدير (Admin)',
                        'cashier' => 'كاشير (Cashier)',
                        'kitchen' => 'مطبخ (Kitchen)',
                        'viewer' => 'متابع تقارير (Viewer)',
                        'watcher' => 'مراقب (Watcher)',
                        default => $state,
                    })
                    ->badge(),
                TextColumn::make('users_count')
                    ->label('عدد المستخدمين')
                    ->counts('users')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label('عدد الصلاحيات')
                    ->counts('permissions')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Role $record): bool => $record->name === 'admin'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
