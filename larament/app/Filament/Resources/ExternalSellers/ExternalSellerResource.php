<?php

namespace App\Filament\Resources\ExternalSellers;

use App\Filament\Resources\ExternalSellers\Pages\CreateExternalSeller;
use App\Filament\Resources\ExternalSellers\Pages\EditExternalSeller;
use App\Filament\Resources\ExternalSellers\Pages\ListExternalSellers;
use App\Filament\Resources\ExternalSellers\RelationManagers\ProductsRelationManager;
use App\Models\ExternalSeller;
use App\Services\FeatureService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ExternalSellerResource extends Resource
{
    protected static ?string $model = ExternalSeller::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المطعم';

    public static function shouldRegisterNavigation(): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::shouldRegisterNavigation();
    }

    public static function canAccess(): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::canAccess();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->outsourcedOrdersEnabled() && parent::getViewAnyAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->outsourcedOrdersEnabled() && parent::getCreateAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return (app(FeatureService::class)->outsourcedOrdersEnabled() && parent::getEditAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return (app(FeatureService::class)->outsourcedOrdersEnabled() && parent::getDeleteAuthorizationResponse($record)->allowed()) ? Response::allow() : Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return (app(FeatureService::class)->outsourcedOrdersEnabled() && parent::getDeleteAnyAuthorizationResponse()->allowed()) ? Response::allow() : Response::deny();
    }

    public static function canGloballySearch(): bool
    {
        return app(FeatureService::class)->outsourcedOrdersEnabled() && parent::canGloballySearch();
    }

    public static function getGlobalSearchResults(string $search): Collection
    {
        if (! app(FeatureService::class)->outsourcedOrdersEnabled()) {
            return collect();
        }

        return parent::getGlobalSearchResults($search);
    }

    public static function getModelLabel(): string
    {
        return 'بائع خارجي';
    }

    public static function getPluralModelLabel(): string
    {
        return 'البائعون الخارجيون';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            TextInput::make('phone')->label('الهاتف')->tel()->maxLength(255),
            Toggle::make('is_active')->label('نشط')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم')->searchable(),
            TextColumn::make('phone')->label('الهاتف'),
            IconColumn::make('is_active')->label('نشط')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ProductsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExternalSellers::route('/'),
            'create' => CreateExternalSeller::route('/create'),
            'edit' => EditExternalSeller::route('/{record}/edit'),
        ];
    }
}
