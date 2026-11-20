<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Enums\OptionalFeature;
use App\Models\Expense;
use App\Models\PlatformConnection;
use App\Models\Revenue;
use App\Models\Shift;
use App\Models\TerminalSession;
use App\Models\User;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use App\Terminals\TerminalSessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way the manager changes a shift's expenses and revenues from the
 * admin panel («مصروفات وإيرادات الورديات»).
 *
 * Every change runs in one transaction with the shift row locked, writes an
 * audit_logs row (context shift_money), and when the shift is already closed:
 * needs shift_money.edit_closed and a reason, recomputes the shift's expected
 * cash (end_cash) and marks it as edited. Counted cash and shortage
 * (real_cash, losses_amount, has_deficit) are legacy and never touched here.
 */
class ShiftMoneyService
{
    public const CONTEXT = 'shift_money';

    public const PERMISSION_MANAGE = 'shift_money.manage';

    public const PERMISSION_EDIT_CLOSED = 'shift_money.edit_closed';

    public function __construct(private ShiftsReportService $reports) {}

    public function canManage(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user !== null && $user->can(self::PERMISSION_MANAGE);
    }

    /**
     * Whether the user may change this shift's money now.
     */
    public function canEdit(Shift $shift, ?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $this->canManage($user)) {
            return false;
        }

        return ! $this->isClosed($shift) || $this->canEditClosed($user);
    }

    /**
     * Closed shifts are editable only while the vendor feature is on and the
     * user holds shift_money.edit_closed (the permission is also gated by
     * the feature, so it disappears from roles when off).
     */
    public function canEditClosed(?User $user = null): bool
    {
        $user ??= Auth::user();

        return $user !== null
            && app(FeatureService::class)->closedShiftEditsEnabled()
            && $user->can(self::PERMISSION_EDIT_CLOSED);
    }

    /**
     * The drawer an admin picked for a row, which must belong to the shift.
     * Null lets the row take the shift's default drawer.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function drawerOf(Shift $shift, array $data): ?int
    {
        $id = $data['terminal_session_id'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        $exists = TerminalSession::query()->where('shift_id', $shift->id)->whereKey((int) $id)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['terminal_session_id' => 'منفذ البيع هذا لا يتبع هذه الوردية.']);
        }

        return (int) $id;
    }

    public function isClosed(Shift $shift): bool
    {
        return (bool) $shift->closed || $shift->end_at !== null;
    }

    /**
     * @param  array{expence_type_id: int|string, amount: float|int|string, notes?: ?string, terminal_session_id?: int|string|null}  $data
     */
    public function addExpense(Shift $shift, array $data, ?string $reason = null): Expense
    {
        return $this->change($shift, $reason, function (Shift $shift) use ($data, $reason): Expense {
            $expense = Expense::query()->create([
                'shift_id' => $shift->id,
                'expence_type_id' => $data['expence_type_id'],
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
                'terminal_session_id' => $this->drawerOf($shift, $data),
            ]);

            AuditLogger::created($expense, $reason, self::CONTEXT, $shift);

            return $expense;
        });
    }

    /**
     * @param  array{expence_type_id?: int|string, amount?: float|int|string, notes?: ?string, terminal_session_id?: int|string|null}  $data
     */
    public function updateExpense(Expense $expense, array $data, ?string $reason = null): Expense
    {
        return $this->change($this->shiftOf($expense), $reason, function (Shift $shift) use ($expense, $data, $reason): Expense {
            $changes = array_intersect_key($data, array_flip(['expence_type_id', 'amount', 'notes']));
            if (array_key_exists('terminal_session_id', $data)) {
                $changes['terminal_session_id'] = $this->drawerOf($shift, $data);
            }
            $this->updateAudited($expense, $changes, $reason, $shift);

            return $expense;
        });
    }

    public function deleteExpense(Expense $expense, ?string $reason = null): void
    {
        $this->change($this->shiftOf($expense), $reason, function (Shift $shift) use ($expense, $reason): void {
            AuditLogger::deleted($expense, $reason, self::CONTEXT, $shift);
            $expense->delete();
        });
    }

    /**
     * @param  array{revenue_type_id: int|string, amount: float|int|string, method?: string, notes?: ?string, terminal_session_id?: int|string|null}  $data
     */
    public function addRevenue(Shift $shift, array $data, ?string $reason = null): Revenue
    {
        return $this->change($shift, $reason, function (Shift $shift) use ($data, $reason): Revenue {
            $revenue = Revenue::query()->create([
                'shift_id' => $shift->id,
                'revenue_type_id' => $data['revenue_type_id'],
                'user_id' => Auth::id(),
                'amount' => $data['amount'],
                'method' => $data['method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'terminal_session_id' => $this->drawerOf($shift, $data),
            ]);

            AuditLogger::created($revenue, $reason, self::CONTEXT, $shift);

            return $revenue;
        });
    }

    /**
     * @param  array{revenue_type_id?: int|string, amount?: float|int|string, method?: string, notes?: ?string, terminal_session_id?: int|string|null}  $data
     */
    public function updateRevenue(Revenue $revenue, array $data, ?string $reason = null): Revenue
    {
        return $this->change($this->shiftOf($revenue), $reason, function (Shift $shift) use ($revenue, $data, $reason): Revenue {
            $changes = array_intersect_key($data, array_flip(['revenue_type_id', 'amount', 'method', 'notes']));
            if (array_key_exists('terminal_session_id', $data)) {
                $changes['terminal_session_id'] = $this->drawerOf($shift, $data);
            }
            $this->updateAudited($revenue, $changes, $reason, $shift);

            return $revenue;
        });
    }

    public function deleteRevenue(Revenue $revenue, ?string $reason = null): void
    {
        $this->change($this->shiftOf($revenue), $reason, function (Shift $shift) use ($revenue, $reason): void {
            AuditLogger::deleted($revenue, $reason, self::CONTEXT, $shift);
            $revenue->delete();
        });
    }

    /**
     * The figures the page header shows: opening and expected cash, expenses
     * and revenues. For an open shift the expected cash is live.
     *
     * @return array{start_cash: float, expected_cash: float, closed: bool, expenses: float, revenues: float}
     */
    public function summary(Shift $shift): array
    {
        $stats = $this->reports->calculateShiftStats($shift);
        $closed = $this->isClosed($shift);
        $expected = round((float) $stats['availableCash'], 2);

        return [
            'start_cash' => round((float) $shift->start_cash, 2),
            'expected_cash' => $expected,
            'closed' => $closed,
            'expenses' => round((float) $stats['expenses'], 2),
            'revenues' => round((float) $stats['revenues'], 2),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(Shift): T  $write
     * @return T
     */
    private function change(Shift $shift, ?string $reason, callable $write): mixed
    {
        return DB::transaction(function () use ($shift, $reason, $write) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());
            $closed = $this->isClosed($locked);

            $this->authorize($locked, $closed);

            if ($closed && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages([
                    'reason' => 'سبب التعديل مطلوب عند تعديل وردية مغلقة',
                ]);
            }

            $result = $write($locked);

            if ($closed) {
                $this->recompute($locked, (string) $reason);
            }

            $shift->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    private function authorize(Shift $shift, bool $closed): void
    {
        $user = Auth::user();

        if (! $this->canManage($user)) {
            throw new AuthorizationException('غير مسموح بإدارة مصروفات وإيرادات الورديات');
        }

        if ($closed && ! app(FeatureService::class)->closedShiftEditsEnabled()) {
            throw new AuthorizationException(OptionalFeature::CLOSED_SHIFT_EDITS->disabledErrorMessage());
        }

        if ($closed && ! $user->can(self::PERMISSION_EDIT_CLOSED)) {
            throw new AuthorizationException('غير مسموح بتعديل الورديات المغلقة');
        }
    }

    /**
     * New expected cash from the same formula the shift was closed with.
     * Counted cash and shortage are legacy and no longer kept up to date.
     */
    private function recompute(Shift $shift, string $reason): void
    {
        $original = $shift->getOriginal();
        $endCash = round((float) $this->reports->calculateShiftStats($shift)['availableCash'], 2);

        $attributes = [
            'end_cash' => $endCash,
            'money_edited_at' => now(),
            'money_edited_count' => (int) $shift->money_edited_count + 1,
        ];

        $shift->forceFill($attributes)->save();
        app(TerminalSessionService::class)->recomputeClosed($shift);

        AuditLogger::updated($shift, $original, $reason, self::CONTEXT, $shift);

        // The cloud day totals for that business date are rebuilt from scratch.
        if ($shift->business_date !== null && PlatformConnection::active() !== null) {
            $date = $shift->business_date->toDateString();
            Outbox::record(DocumentType::DaySummary, DateDocument::uuid(DocumentType::DaySummary, $date), key: $date);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateAudited(Model $model, array $data, ?string $reason, Shift $shift): void
    {
        $original = $model->getOriginal();
        $model->fill($data);

        if (! $model->isDirty()) {
            return;
        }

        $model->save();
        AuditLogger::updated($model, $original, $reason, self::CONTEXT, $shift);
    }

    private function shiftOf(Expense|Revenue $row): Shift
    {
        return Shift::query()->findOrFail($row->shift_id);
    }
}
