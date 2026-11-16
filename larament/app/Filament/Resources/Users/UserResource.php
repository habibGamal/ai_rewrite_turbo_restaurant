<?php

namespace App\Filament\Resources\Users;

use App\Auth\BadgeService;
use App\Filament\Actions\GeneratePasswordAction;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة المبيعات';

    public static function getModelLabel(): string
    {
        return 'مستخدم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المستخدمين';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name',
            'email',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var User $record */
        return ['email' => $record->email];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم المستخدم')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('البريد الإلكتروني')
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label('كلمة المرور')
                            ->password()
                            ->required(fn ($livewire) => $livewire instanceof CreateUser)
                            ->revealable(filament()->arePasswordsRevealable())
                            ->rule(Password::default())
                            ->autocomplete('new-password')
                            ->dehydrated(fn ($state): bool => filled($state))
                            ->dehydrateStateUsing(fn ($state): string => Hash::make($state))
                            ->live(debounce: 500)
                            ->same('passwordConfirmation')
                            ->suffixActions([
                                GeneratePasswordAction::make(),
                            ]),
                        TextInput::make('passwordConfirmation')
                            ->label('تأكيد كلمة المرور')
                            ->password()
                            ->revealable(filament()->arePasswordsRevealable())
                            ->required()
                            ->visible(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                        Select::make('roles')
                            ->label('الأدوار والصلاحيات')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->required(),
                    ]),
                Section::make('الدخول بالبادج')
                    ->description('البادج كارت عليه باركود، الكاشير بيعمله سكان فيدخل نقطة البيع على طول. اطبعه من «طباعة بادج» في جدول المستخدمين.')
                    ->schema([
                        TextInput::make('badge_pin')
                            ->label('الرقم السري للبادج (PIN)')
                            ->helperText('٤ أرقام. مطلوب للمديرين (اللي ليهم دخول لوحة التحكم) مع البادج. سيبه فاضي عشان يفضل زي ما هو.')
                            ->password()
                            ->revealable()
                            ->regex('/^\d{4}$/')
                            ->validationMessages(['regex' => 'الرقم السري لازم يكون ٤ أرقام.'])
                            ->autocomplete('off')
                            ->dehydrated(fn ($state): bool => filled($state)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('الأدوار')
                    ->badge()
                    ->color('info')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('printBadge')
                    ->label('طباعة بادج')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->visible(fn (User $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => 'طباعة بادج لـ '.$record->name)
                    ->modalDescription(fn (User $record): string => $record->badge_issued_at !== null
                        ? 'هيتعمل بادج جديد والبادج القديم هيبطل يشتغل.'
                        : 'هيتعمل بادج جديد للمستخدم ده.')
                    ->action(function (User $record, Action $action): void {
                        $code = app(BadgeService::class)->issue($record);
                        session()->put('badge_print.'.$record->getKey(), $code);

                        $action->redirect(route('users.badge.print', $record));
                    }),
                Action::make('revokeBadge')
                    ->label('إلغاء البادج')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->badge_issued_at !== null && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(BadgeService::class)->revoke($record);
                        Notification::make()->success()->title('تم إلغاء البادج')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Explicit is_vendor filter: the HidesVendor scope lets primary-key
        // lookups through, which would expose /users/{vendor}/edit.
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->where('users.is_vendor', false);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
