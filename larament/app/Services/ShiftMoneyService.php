<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Models\Expense;
use App\Models\PlatformConnection;
use App\Models\Revenue;
use App\Models\Shift;
use App\Models\User;
use App\Sync\DateDocument;
use App\Sync\DocumentType;
use App\Sync\Outbox;
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
 * cash (end_cash), shortage (losses_amount) and has_deficit, and marks it as
 * edited. What the cashier counted (real_cash) is never changed.
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

        return ! $this->isClosed($shift) || $user->can(self::PERMISSION_EDIT_CLOSED);
    }

    public function isClosed(Shift $shift): bool
    {
        return (bool) $shift->closed || $shift->end_at !== null;
    }

    /**
     * @param  array{expence_type_id: int|string, amount: float|int|string, notes?: ?string}  $data
     */
    public function addExpense(Shift $shift, array $data, ?string $reason = null): Expense
    {
        return $this->change($shift, $reason, function (Shift $shift) use ($data, $reason): Expense {
            $expense = Expense::query()->create([
                'shift_id' => $shift->id,
                'expence_type_id' => $data['expence_type_id'],
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            AuditLogger::created($expense, $reason, self::CONTEXT, $shift);

            return $expense;
        });
    }

    /**
     * @param  array{expence_type_id?: int|string, amount?: float|int|string, notes?: ?string}  $data
     */
    public function updateExpense(Expense $expense, array $data, ?string $reason = null): Expense
    {
        return $this->change($this->shiftOf($expense), $reason, function (Shift $shift) use ($expense, $data, $reason): Expense {
            $this->updateAudited($expense, array_intersect_key($data, array_flip(['expence_type_id', 'amount', 'notes'])), $reason, $shift);

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
     * @param  array{revenue_type_id: int|string, amount: float|int|string, method?: string, notes?: ?string}  $data
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
            ]);

            AuditLogger::created($revenue, $reason, self::CONTEXT, $shift);

            return $revenue;
        });
    }

    /**
     * @param  array{revenue_type_id?: int|string, amount?: float|int|string, method?: string, notes?: ?string}  $data
     */
    public function updateRevenue(Revenue $revenue, array $data, ?string $reason = null): Revenue
    {
        return $this->change($this->shiftOf($revenue), $reason, function (Shift $shift) use ($revenue, $data, $reason): Revenue {
            $this->updateAudited($revenue, array_intersect_key($data, array_flip(['revenue_type_id', 'amount', 'method', 'notes'])), $reason, $shift);

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
     * The figures the page header shows: opening, expected, counted cash and
     * the shortage (positive) or surplus (negative). For an open shift the
     * expected cash is live and nothing is counted yet.
     *
     * @return array{start_cash: float, expected_cash: float, counted_cash: ?float, shortage: ?float, closed: bool, expenses: float, revenues: float}
     */
    public function summary(Shift $shift): array
    {
        $stats = $this->reports->calculateShiftStats($shift);
        $closed = $this->isClosed($shift);
        $expected = round((float) $stats['availableCash'], 2);
        $counted = $shift->real_cash !== null ? round((float) $shift->real_cash, 2) : null;

        return [
            'start_cash' => round((float) $shift->start_cash, 2),
            'expected_cash' => $expected,
            'counted_cash' => $closed ? $counted : null,
            'shortage' => $closed && $counted !== null ? round($expected - $counted, 2) : null,
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

        if ($closed && ! $user->can(self::PERMISSION_EDIT_CLOSED)) {
            throw new AuthorizationException('غير مسموح بتعديل الورديات المغلقة');
        }
    }

    /**
     * New expected cash from the same formula the shift was closed with; the
     * counted cash stays, so the shortage moves by the change.
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

        if ($shift->real_cash !== null) {
            $difference = round($endCash - (float) $shift->real_cash, 2);
            $attributes['losses_amount'] = $difference;
            $attributes['has_deficit'] = $difference > 0;
        }

        $shift->forceFill($attributes)->save();

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
