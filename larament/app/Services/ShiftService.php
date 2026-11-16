<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\HeldSale;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Orders\Behaviors\OrderTypeBehavior;
use App\Orders\OrderTypeCatalog;
use App\Terminals\TerminalSessionService;
use Exception;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly OrderTypeCatalog $catalog,
        private readonly ShiftsReportService $shiftsReportService,
        private readonly TerminalSessionService $terminalSessions,
    ) {}

    /**
     * Get the current active shift for a user
     */
    public function getCurrentShift(): ?Shift
    {
        return Shift::where('end_at', null)
            ->where('closed', false)
            ->first();
    }

    /**
     * Start a new shift for a user
     */
    public function startShift(float $startCash, ?User $user = null): Shift
    {
        shouldDayBeOpen();

        // Check if user already has an active shift
        $activeShift = $this->getCurrentShift();
        if ($activeShift) {
            throw new Exception('User already has an active shift');
        }

        return DB::transaction(function () use ($user, $startCash) {
            $shift = Shift::create([
                'start_cash' => $startCash,
                'start_at' => now(),
                'business_date' => app(InventoryDailyAggregationService::class)->currentBusinessDate()->toDateString(),
                'closed' => false,
                'user_id' => $user ? $user->id : auth()->id(),
            ]);

            // Every shift has at least one drawer session; with multiple
            // terminals off it is the hidden default drawer.
            $this->terminalSessions->openDefault($shift, $user ?? auth()->user());

            // Transfer open web orders from previous shift if setting is enabled
            if ($this->settingsService->isWebOrdersShiftTransferAllowed()) {
                $this->transferOpenWebOrders($shift->id);
            }

            return $shift;
        });
    }

    /**
     * Count open web orders in the last closed shift
     */
    public function countOpenWebOrdersInLastClosedShift(): int
    {
        $lastShift = Shift::where('closed', true)
            ->orderBy('end_at', 'desc')
            ->first();

        if (! $lastShift) {
            return 0;
        }

        return Order::where('shift_id', $lastShift->id)
            ->whereIn('type', $this->carryingTypeCodes())
            ->whereIn('status', [OrderStatus::PROCESSING, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::PENDING])
            ->count();
    }

    /**
     * Transfer open web orders from the last closed shift to the new shift
     */
    private function transferOpenWebOrders(int $newShiftId): void
    {
        // Get the last closed shift
        $lastShift = Shift::where('closed', true)
            ->orderBy('end_at', 'desc')
            ->first();

        if (! $lastShift) {
            return;
        }

        // Find all open web orders from the last shift
        Order::where('shift_id', $lastShift->id)
            ->whereIn('type', $this->carryingTypeCodes())
            ->whereIn('status', [OrderStatus::PROCESSING, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::PENDING])
            ->update(['shift_id' => $newShiftId]);
    }

    /**
     * End the current shift for a user
     */
    public function endShift(float $realCash): Shift
    {

        $currentShift = $this->getCurrentShift();
        if (! $currentShift) {
            throw new Exception('No active shift found');
        }

        // Build query for processing orders check
        $processingOrdersQuery = $currentShift->orders()
            ->whereIn('status', [OrderStatus::PROCESSING, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::PENDING]);

        // If web orders shift transfer is enabled, exclude web orders from the check
        if ($this->settingsService->isWebOrdersShiftTransferAllowed()) {
            $processingOrdersQuery->whereNotIn('type', $this->carryingTypeCodes());
        }

        $processingOrders = $processingOrdersQuery->exists();

        if ($processingOrders) {
            throw new Exception('لا يمكن إنهاء الشيفت لوجود طلبات قيد المعالجة');
        }

        return DB::transaction(function () use ($currentShift, $realCash) {
            $this->terminalSessions->closeAllOpen($currentShift, auth()->user());

            // Expected drawer cash is the report's "النقدية المتاحة": opening cash + cash payments
            // received in this shift + external cash − seller settlements − expenses. Payments count
            // in the shift that received them, so a pay-later order adds only what was actually paid
            // here, and a later collection adds to the shift that collected it.
            $endCash = round((float) $this->shiftsReportService->calculateShiftStats($currentShift)['availableCash'], 2);

            // Positive = shortage, negative = surplus (same sign as Shift::deficit).
            $difference = round($endCash - $realCash, 2);

            $currentShift->update([
                'end_cash' => $endCash,
                'real_cash' => $realCash,
                'losses_amount' => $difference,
                'has_deficit' => $difference > 0,
                'end_at' => now(),
                'closed' => true,
            ]);

            // Carts parked on the instant-sale screen never became orders; they end with the shift.
            HeldSale::query()->where('shift_id', $currentShift->id)->delete();

            return $currentShift->fresh();
        });
    }

    /**
     * @return list<string>
     */
    private function carryingTypeCodes(): array
    {
        return $this->catalog->codesWhere(
            fn (OrderTypeBehavior $behavior): bool => $behavior->carriesAcrossShifts()
        );
    }

    /**
     * Check if user can start a new shift
     */
    public function canStartShift(): bool
    {
        return $this->getCurrentShift() === null;
    }

    /**
     * Check if user can end current shift
     */
    public function canEndShift(): bool
    {
        return $this->getCurrentShift() !== null;
    }
}
