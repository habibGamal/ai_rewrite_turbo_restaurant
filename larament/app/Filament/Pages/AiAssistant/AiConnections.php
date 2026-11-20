<?php

namespace App\Filament\Pages\AiAssistant;

use App\Mcp\McpAccess;
use App\Models\McpToolCall;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

/**
 * «المساعد الذكي»: how to connect ChatGPT, Claude or Gemini, and the AI
 * clients that can read the shop's data now, with a revoke button. A user
 * sees their own connections; an admin sees everyone's.
 */
class AiConnections extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'المساعد الذكي';

    protected static ?string $title = 'المساعد الذكي: التطبيقات المتصلة';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 110;

    protected static ?string $slug = 'ai-assistant';

    protected string $view = 'filament.pages.ai-assistant.connections';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(McpAccess::class)->denial($user) === null;
    }

    public function mcpUrl(): string
    {
        return url('/mcp/pos');
    }

    public static function seesEveryone(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }

    /**
     * Live connections: access tokens not revoked whose own expiry or refresh
     * token is still valid (a refresh replaces the access token, so one
     * connection is one row).
     *
     * @return Builder<Token>
     */
    public static function connectionsQuery(): Builder
    {
        $now = now();

        return Token::query()
            ->with(['client'])
            ->where('revoked', false)
            ->where(function (Builder $query) use ($now): void {
                $query->where('expires_at', '>', $now)
                    ->orWhereExists(function ($sub) use ($now): void {
                        $sub->from('oauth_refresh_tokens')
                            ->whereColumn('oauth_refresh_tokens.access_token_id', 'oauth_access_tokens.id')
                            ->where('oauth_refresh_tokens.revoked', false)
                            ->where('oauth_refresh_tokens.expires_at', '>', $now);
                    });
            })
            ->when(! self::seesEveryone(), fn (Builder $query) => $query->where('user_id', auth()->id()));
    }

    /** A user's display name; the vendor shows as «الدعم الفني». */
    public static function userName(mixed $userId): string
    {
        $user = User::query()->withoutGlobalScopes()->find($userId);

        return match (true) {
            ! $user instanceof User => '—',
            $user->isVendor() => User::VENDOR_DISPLAY_NAME,
            default => (string) $user->name,
        };
    }

    /** Revoke a connection: the access token and its refresh tokens. */
    public static function revoke(Token $token): void
    {
        DB::transaction(function () use ($token): void {
            $token->revoke();
            DB::table('oauth_refresh_tokens')->where('access_token_id', $token->getKey())->update(['revoked' => true]);
        });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::connectionsQuery()->latest('created_at'))
            ->heading('التطبيقات المتصلة')
            ->description('كل تطبيق هنا يستطيع قراءة بيانات المتجر نيابة عن المستخدم الذي وافق عليه، دون أن يعدّل شيئاً.')
            ->columns([
                TextColumn::make('client.name')
                    ->label('التطبيق')
                    ->default('—')
                    ->weight('bold'),
                TextColumn::make('user_id')
                    ->label('المستخدم')
                    ->formatStateUsing(fn ($state): string => self::userName($state))
                    ->visible(fn (): bool => self::seesEveryone()),
                TextColumn::make('created_at')
                    ->label('آخر تجديد')
                    ->dateTime('Y-m-d H:i'),
                TextColumn::make('last_used')
                    ->label('آخر استخدام')
                    ->state(fn (Token $record): ?string => McpToolCall::query()
                        ->where('user_id', $record->getAttribute('user_id'))
                        ->where('client_id', $record->getAttribute('client_id'))
                        ->max('created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يُستخدم بعد'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('إلغاء الربط')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('إلغاء ربط التطبيق')
                    ->modalDescription('لن يستطيع هذا التطبيق قراءة بيانات المتجر بعد الآن، ويلزم ربطه من جديد لاستخدامه.')
                    ->modalSubmitActionLabel('إلغاء الربط')
                    ->action(function (Token $record): void {
                        self::revoke($record);
                        Notification::make()->title('تم إلغاء ربط التطبيق')->success()->send();
                    }),
            ])
            ->emptyStateHeading('لا توجد تطبيقات متصلة')
            ->emptyStateDescription('اتبع الخطوات أعلاه لربط ChatGPT أو Claude أو Gemini.')
            ->emptyStateIcon('heroicon-o-sparkles')
            ->paginated([10, 25, 50]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('revokeAll')
                ->label(fn (): string => self::seesEveryone() ? 'إلغاء ربط كل التطبيقات' : 'إلغاء ربط كل تطبيقاتي')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => self::seesEveryone()
                    ? 'سيُلغى ربط كل التطبيقات لكل المستخدمين، ويلزم ربطها من جديد.'
                    : 'سيُلغى ربط كل التطبيقات التي وافقت عليها، ويلزم ربطها من جديد.')
                ->visible(fn (): bool => self::connectionsQuery()->exists())
                ->action(function (): void {
                    $count = 0;
                    self::connectionsQuery()->get()->each(function (Token $token) use (&$count): void {
                        self::revoke($token);
                        $count++;
                    });
                    Notification::make()->title("تم إلغاء ربط {$count} تطبيق")->success()->send();
                }),
        ];
    }
}
