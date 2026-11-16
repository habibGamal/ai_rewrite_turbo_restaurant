<?php

namespace App\Http\Controllers;

use App\Services\FeatureService;
use App\Services\SettingsService;
use App\Services\ShiftLoggingService;
use App\Services\ShiftService;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftController extends Controller
{
    public function __construct(
        private ShiftService $shiftService,
        private ShiftLoggingService $loggingService,
        private SettingsService $settingsService
    ) {}

    /**
     * Show start shift page
     */
    public function showStartShift()
    {
        if (app(FeatureService::class)->multiTerminalEnabled()) {
            return redirect()->route('terminals.open');
        }

        if (! $this->shiftService->canStartShift()) {
            return redirect()->route('orders.index');
        }

        return Inertia::render('Shifts/StartShift');
    }

    /**
     * Start a new shift
     */
    public function startShift(Request $request)
    {
        if (app(FeatureService::class)->multiTerminalEnabled()) {
            return redirect()->route('terminals.open');
        }

        $validated = $request->validate([
            'start_cash' => 'required|numeric|min:0',
        ]);
        try {

            if (! $this->shiftService->canStartShift()) {
                return redirect()->route('orders.index')->with('info', 'لديك وردية نشطة بالفعل');
            }

            // Get web orders count before starting shift
            $transferredOrdersCount = 0;

            if ($this->settingsService->isWebOrdersShiftTransferAllowed()) {
                $transferredOrdersCount = $this->shiftService->countOpenWebOrdersInLastClosedShift();
            }

            $shift = $this->shiftService->startShift($validated['start_cash']);

            // Log shift start with transfer info
            $logData = [
                'id' => $shift->id,
                'start_cash' => $validated['start_cash'],
                'date' => $shift->created_at->format('Y-m-d H:i:s'),
            ];

            if ($transferredOrdersCount > 0) {
                $logData['transferred_web_orders'] = $transferredOrdersCount;
            }

            $this->loggingService->logShiftAction('start', $logData);

            return redirect()->route('orders.index')->with('success', 'تم بدء الوردية بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في بدء الوردية', [
                'error' => $e->getMessage(),
                'start_cash' => $validated['start_cash'],
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء بدء الوردية: '.$e->getMessage()]);
        }
    }

    /**
     * End current shift
     */
    public function endShift(Request $request)
    {
        if (! auth()->user()->canManageOrders()) {
            abort(403, 'غير مسموح لك بإنهاء الشيفت');
        }

        $validated = $request->validate([
            'real_end_cash' => 'required|numeric|min:0',
        ]);

        try {
            if (! $this->shiftService->canEndShift()) {
                return back()->withErrors(['error' => 'لا يوجد شيفت نشط']);
            }

            $currentShift = $this->shiftService->getCurrentShift();
            $endedShift = $this->shiftService->endShift($validated['real_end_cash']);

            // Log shift end
            $this->loggingService->logShiftAction('end', [
                'id' => $endedShift->id,
                'start_cash' => $endedShift->start_cash,
                'end_cash' => $endedShift->end_cash,
                'real_end_cash' => $validated['real_end_cash'],
                'date' => $endedShift->updated_at->format('Y-m-d H:i:s'),
            ]);

            return redirect()->route('shifts.start')->with('success', 'تم إنهاء الشيفت بنجاح');
        } catch (Exception $e) {
            $this->loggingService->logAction('فشل في إنهاء الوردية', [
                'real_end_cash' => $validated['real_end_cash'],
                'error' => $e->getMessage(),
            ], 'error');

            return back()->withErrors(['error' => 'حدث خطأ أثناء إنهاء الشيفت: '.$e->getMessage()]);
        }
    }
}
