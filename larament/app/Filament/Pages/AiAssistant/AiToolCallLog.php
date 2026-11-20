<?php

namespace App\Filament\Pages\AiAssistant;

use App\Enums\OptionalFeature;
use App\Models\McpToolCall;
use App\Models\User;
use App\Services\FeatureService;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * «سجل المساعد الذكي»: every question an AI client asked the shop's data,
 * as tool calls. Admins only.
 */
class AiToolCallLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = 'سجل المساعد الذكي';

    protected static ?string $title = 'سجل المساعد الذكي';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static ?int $navigationSort = 111;

    protected static ?string $slug = 'ai-assistant-log';

    protected string $view = 'filament.pages.ai-assistant.log';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->isAdmin()
            && app(FeatureService::class)->isEnabled(OptionalFeature::AI_ASSISTANT);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(McpToolCall::query()->latest('id'))
            ->description('كل طلب بيانات أرسله تطبيق ذكاء اصطناعي متصل، ومن طلبه ومتى.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('الوقت')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('user_id')
                    ->label('المستخدم')
                    ->formatStateUsing(fn ($state): string => AiConnections::userName($state)),
                TextColumn::make('client_name')
                    ->label('التطبيق')
                    ->placeholder('—'),
                TextColumn::make('tool')
                    ->label('الأداة')
                    ->badge()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('arguments')
                    ->label('المعطيات')
                    ->state(fn (McpToolCall $record): string => $record->arguments === null ? '—' : (string) json_encode($record->arguments, JSON_UNESCAPED_UNICODE))
                    ->fontFamily(FontFamily::Mono)
                    ->limit(80)
                    ->wrap(),
                IconColumn::make('ok')
                    ->label('نجح')
                    ->boolean(),
                TextColumn::make('error')
                    ->label('الخطأ')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('duration_ms')
                    ->label('المدة (مللي ثانية)')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tool')
                    ->label('الأداة')
                    ->options(fn (): array => McpToolCall::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()),
                TernaryFilter::make('ok')
                    ->label('النتيجة')
                    ->trueLabel('نجح')
                    ->falseLabel('فشل'),
            ])
            ->emptyStateHeading('لا توجد طلبات بعد')
            ->emptyStateIcon('heroicon-o-list-bullet')
            ->paginated([25, 50, 100]);
    }
}
