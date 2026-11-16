<?php

namespace App\Filament\Widgets\ShiftMoney\Concerns;

use App\Models\Shift;
use App\Services\ShiftMoneyService;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared by the shift money page widgets: the shift picked on the page, the
 * closed-shift reason field and running a change through ShiftMoneyService.
 */
trait InteractsWithSelectedShift
{
    use InteractsWithPageFilters;

    public const CHANGED_EVENT = 'shift-money-changed';

    protected function selectedShift(): ?Shift
    {
        $id = $this->pageFilters['shift_id'] ?? null;

        return $id ? Shift::query()->with('user')->find($id) : null;
    }

    protected function shiftMoney(): ShiftMoneyService
    {
        return app(ShiftMoneyService::class);
    }

    protected function canEditSelectedShift(): bool
    {
        $shift = $this->selectedShift();

        return $shift !== null && $this->shiftMoney()->canEdit($shift);
    }

    protected function selectedShiftIsClosed(): bool
    {
        $shift = $this->selectedShift();

        return $shift !== null && $this->shiftMoney()->isClosed($shift);
    }

    /**
     * Required, and only shown, when the shift is closed.
     */
    protected function reasonField(): Textarea
    {
        return Textarea::make('reason')
            ->label('سبب التعديل')
            ->helperText('الوردية مغلقة: سيتم تسجيل التعديل وإعادة حساب النقدية المتوقعة.')
            ->rows(2)
            ->maxLength(1000)
            ->required(fn (): bool => $this->selectedShiftIsClosed())
            ->visible(fn (): bool => $this->selectedShiftIsClosed());
    }

    /**
     * @param  callable(): mixed  $change
     */
    protected function runShiftMoneyChange(callable $change, string $success): void
    {
        try {
            $change();
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('تعذر حفظ التعديل')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
        $this->dispatch(self::CHANGED_EVENT);
    }
}
