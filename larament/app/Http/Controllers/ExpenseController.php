<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenceType;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function __construct(
        private ShiftService $shiftService
    ) {
    }

    /**
     * Display a listing of expenses for the current shift
     */
    public function index()
    {
        $currentShift = $this->shiftService->getCurrentShift();

        if (!$currentShift) {
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
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'expenseTypeId' => 'required|exists:expence_types,id',
            'description' => 'required|string|max:255',
        ]);

        $currentShift = $this->shiftService->getCurrentShift();

        if (!$currentShift) {
            return redirect()->route('shifts.start');
        }

        DB::transaction(function () use ($request, $currentShift) {
            Expense::create([
                'shift_id' => $currentShift->id,
                'expence_type_id' => $request->expenseTypeId,
                'amount' => $request->amount,
                'notes' => $request->description,
            ]);
        });

        return back()->with('success', 'تم إضافة المصروف بنجاح');
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

        if (!$currentShift || $expense->shift_id !== $currentShift->id) {
            return back()->withErrors(['message' => 'لا يمكن تعديل هذا المصروف']);
        }

        DB::transaction(function () use ($request, $expense) {
            $expense->update([
                'expence_type_id' => $request->expenseTypeId,
                'amount' => $request->amount,
                'notes' => $request->description,
            ]);
        });

        return back()->with('success', 'تم تعديل المصروف بنجاح');
    }

    /**
     * Remove the specified expense
     */
    public function destroy(Expense $expense)
    {
        $currentShift = $this->shiftService->getCurrentShift();

        if (!$currentShift || $expense->shift_id !== $currentShift->id) {
            return back()->withErrors(['message' => 'لا يمكن حذف هذا المصروف']);
        }

        DB::transaction(function () use ($expense) {
            $expense->delete();
        });

        return back()->with('success', 'تم حذف المصروف بنجاح');
    }
}
