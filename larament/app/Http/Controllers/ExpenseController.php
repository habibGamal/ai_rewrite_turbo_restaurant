<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\TerminalSession;
use App\Services\FeatureService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use App\Terminals\TerminalSessionResolver;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function __construct(
        private ShiftService $shiftService,
        private ShiftLoggingService $loggingService
    ) {}

    /**
     * Display a listing of expenses for the current shift
     */
    public function index()
    {
        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift) {
            return redirect()->route('shifts.start');
        }

        $expenses = Expense::with('expenceType')
            ->where('shift_id', $currentShift->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $expenseTypes = ExpenceType::all();

        return Inertia::render('Orders/Index', [
            'expenses' => $expenses,
            'expenseTypes' => $expenseTypes,
        ]);
    }

    /**
     * Store a newly created expense
     */
    public function store(Request $request)
    {
        if (! auth()->user()->can('expense.create')) {
            abort(403, 'غير مسموح بإضافة مصروف');
        }

        $request->validate([
            'amount' => 'required|numeric|min:0',
            'expenseTypeId' => 'required|exists:expence_types,id',
            'description' => 'required|string|max:255',
        ]);

        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift) {
            return redirect()->route('shifts.start');
        }

        try {
            DB::transaction(function () use ($request, $currentShift) {
                $expenseType = ExpenceType::find($request->expenseTypeId);

                $expense = Expense::create([
                    'shift_id' => $currentShift->id,
                    'expence_type_id' => $request->expenseTypeId,
                    'amount' => $request->amount,
                    'notes' => $request->description,
                ]);

                AuditLogger::created($expense, context: 'pos_expense', shift: $currentShift);

                // Log expense creation
                $this->loggingService->logExpenseAction('create', [
                    'id' => $expense->id,
                    'expense_type_name' => $expenseType->name,
                    'amount' => $request->amount,
                    'description' => $request->description,
                ]);
            });

            return back()->with('success', 'تم إضافة المصروف بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إضافة مصروف', [
                'expense_type_id' => $request->expenseTypeId,
                'amount' => $request->amount,
                'description' => $request->description,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['message' => 'حدث خطأ أثناء إضافة المصروف: '.$e->getMessage()]);
        }
    }

    /**
     * Update the specified expense
     */
    public function update(Request $request, Expense $expense)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'expenseTypeId' => 'required|exists:expence_types,id',
            'description' => 'required|string|max:255',
        ]);

        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift || $expense->shift_id !== $currentShift->id) {
            return back()->withErrors(['message' => 'لا يمكن تعديل هذا المصروف']);
        }

        if (! $this->belongsToThisDrawer($expense)) {
            return back()->withErrors(['message' => 'لا يمكن تعديل مصروف منفذ بيع آخر أو منفذ بيع مغلق']);
        }

        try {
            DB::transaction(function () use ($request, $expense) {
                $oldExpenseType = $expense->expenceType;
                $newExpenseType = ExpenceType::find($request->expenseTypeId);

                $oldData = [
                    'expense_type_name' => $oldExpenseType->name,
                    'amount' => $expense->amount,
                    'description' => $expense->notes,
                ];

                $original = $expense->getOriginal();

                $expense->update([
                    'expence_type_id' => $request->expenseTypeId,
                    'amount' => $request->amount,
                    'notes' => $request->description,
                ]);

                if ($expense->wasChanged()) {
                    AuditLogger::updated($expense, $original, context: 'pos_expense');
                }

                // Log expense update
                $this->loggingService->logExpenseAction('update', [
                    'id' => $expense->id,
                    'old_data' => $oldData,
                    'new_data' => [
                        'expense_type_name' => $newExpenseType->name,
                        'amount' => $request->amount,
                        'description' => $request->description,
                    ],
                ]);
            });

            return back()->with('success', 'تم تعديل المصروف بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في تعديل مصروف', [
                'expense_id' => $expense->id,
                'expense_type_id' => $request->expenseTypeId,
                'amount' => $request->amount,
                'description' => $request->description,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['message' => 'حدث خطأ أثناء تعديل المصروف: '.$e->getMessage()]);
        }
    }

    /**
     * Remove the specified expense
     */
    public function destroy(Expense $expense)
    {
        if (! auth()->user()->can('expense.delete')) {
            abort(403, 'غير مسموح بحذف المصروف');
        }

        $currentShift = $this->shiftService->getCurrentShift();

        if (! $currentShift || $expense->shift_id !== $currentShift->id) {
            return back()->withErrors(['message' => 'لا يمكن حذف هذا المصروف']);
        }

        if (! $this->belongsToThisDrawer($expense)) {
            return back()->withErrors(['message' => 'لا يمكن حذف مصروف منفذ بيع آخر أو منفذ بيع مغلق']);
        }

        try {
            DB::transaction(function () use ($expense) {
                $expenseData = [
                    'id' => $expense->id,
                    'expense_type_name' => $expense->expenceType->name,
                    'amount' => $expense->amount,
                    'description' => $expense->notes,
                ];

                AuditLogger::deleted($expense, context: 'pos_expense');
                $expense->delete();

                // Log expense deletion
                $this->loggingService->logExpenseAction('delete', $expenseData);
            });

            return back()->with('success', 'تم حذف المصروف بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في حذف مصروف', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['message' => 'حدث خطأ أثناء حذف المصروف: '.$e->getMessage()]);
        }
    }

    /**
     * With several drawers, an expense is changed only from the drawer that
     * recorded it, while that drawer is still open: a closed drawer's expected
     * cash is frozen.
     */
    private function belongsToThisDrawer(Expense $expense): bool
    {
        $sessionId = $expense->getAttribute('terminal_session_id');

        if ($sessionId === null || ! app(FeatureService::class)->multiTerminalEnabled()) {
            return true;
        }

        $session = TerminalSession::query()->find($sessionId);
        if ($session === null || ! $session->isOpen()) {
            return false;
        }

        $current = app(TerminalSessionResolver::class)->current();

        return $current === null || (int) $current->getKey() === (int) $sessionId;
    }
}
