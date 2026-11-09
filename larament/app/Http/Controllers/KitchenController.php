<?php

namespace App\Http\Controllers;

use App\Enums\KdsSound;
use App\Enums\KitchenTicketStatus;
use App\Enums\SettingKey;
use App\Exceptions\KitchenException;
use App\Http\Resources\KitchenTicketResource;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\User;
use App\Services\Kitchen\KitchenTicketService;
use App\Services\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    public function __construct(
        private readonly KitchenTicketService $kitchenTicketService,
        private readonly ShiftService $shiftService,
    ) {}

    /**
     * Kitchen board. Not limited to the current shift: web orders can cross shifts.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('kitchen.access'), 403);

        $recallSince = now()->subMinutes((int) setting(SettingKey::KDS_RECALL_MINUTES));

        $tickets = KitchenTicket::query()
            ->with(['order.customer', 'rounds', 'items'])
            ->where(function ($query) use ($recallSince): void {
                $query->where('status', KitchenTicketStatus::ACTIVE)
                    ->orWhere(function ($query) use ($recallSince): void {
                        $query->where('status', KitchenTicketStatus::DONE)
                            ->where('done_at', '>=', $recallSince);
                    })
                    ->orWhere(function ($query): void {
                        $query->where('status', KitchenTicketStatus::CANCELLED)
                            ->whereNull('cancel_acknowledged_at');
                    });
            })
            ->orderBy('first_sent_at')
            ->orderBy('id')
            ->get();

        $stations = KitchenStation::query()
            ->active()
            ->get()
            ->map(fn (KitchenStation $station): array => [
                'id' => $station->id,
                'name' => $station->name,
            ])
            ->values()
            ->all();

        return Inertia::render('Kitchen/Index', [
            'tickets' => KitchenTicketResource::collection($tickets)->resolve(),
            'stations' => $stations,
            'kdsSettings' => $this->kdsSettings(),
            'serverNow' => now()->toIso8601String(),
            'hasActiveShift' => $this->shiftService->getCurrentShift() !== null,
        ]);
    }

    public function finishItem(Request $request, KitchenTicketItem $item): RedirectResponse
    {
        abort_unless($request->user()?->can('kitchen.access'), 403);

        return $this->mutate(fn () => $this->kitchenTicketService->finishItem($item, $this->actor($request)));
    }

    public function undoItem(Request $request, KitchenTicketItem $item): RedirectResponse
    {
        abort_unless($request->user()?->can('kitchen.access'), 403);

        return $this->mutate(fn () => $this->kitchenTicketService->undoItem($item));
    }

    public function finishTicket(Request $request, KitchenTicket $ticket): RedirectResponse
    {
        abort_unless($request->user()?->can('kitchen.access'), 403);

        $validated = $request->validate([
            'station_id' => ['sometimes', 'nullable', 'integer', 'exists:kitchen_stations,id'],
        ]);

        $stationId = isset($validated['station_id']) ? (int) $validated['station_id'] : null;

        return $this->mutate(fn () => $this->kitchenTicketService->finishTicket(
            $ticket,
            $stationId,
            $this->actor($request),
        ));
    }

    public function acknowledge(Request $request, KitchenTicket $ticket): RedirectResponse
    {
        abort_unless($request->user()?->can('kitchen.access'), 403);

        return $this->mutate(fn () => $this->kitchenTicketService->acknowledge($ticket));
    }

    public function rush(Request $request, KitchenTicket $ticket): RedirectResponse
    {
        abort_unless(
            $request->user()?->can('kitchen.access') || $request->user()?->can('pos.access'),
            403,
        );

        $request->validate([
            'is_rush' => ['required', 'boolean'],
        ]);

        return $this->mutate(fn () => $this->kitchenTicketService->setRush($ticket, $request->boolean('is_rush')));
    }

    /**
     * @return array{
     *     item_tracking: bool,
     *     warning_percent: int,
     *     recall_minutes: int,
     *     new_order_sound: string,
     *     change_sound: string,
     *     cancel_sound: string,
     *     sound_volume: int,
     *     reminder_seconds: int
     * }
     */
    private function kdsSettings(): array
    {
        return [
            'item_tracking' => setting(SettingKey::KDS_ITEM_TRACKING) === 'true',
            'warning_percent' => (int) setting(SettingKey::KDS_WARNING_PERCENT),
            'recall_minutes' => (int) setting(SettingKey::KDS_RECALL_MINUTES),
            'new_order_sound' => $this->sound(SettingKey::KDS_NEW_ORDER_SOUND),
            'change_sound' => $this->sound(SettingKey::KDS_CHANGE_SOUND),
            'cancel_sound' => $this->sound(SettingKey::KDS_CANCEL_SOUND),
            'sound_volume' => (int) setting(SettingKey::KDS_SOUND_VOLUME),
            'reminder_seconds' => (int) setting(SettingKey::KDS_REMINDER_SECONDS),
        ];
    }

    private function sound(SettingKey $key): string
    {
        $sound = KdsSound::tryFrom(setting($key));

        return $sound instanceof KdsSound ? $sound->value : KdsSound::NONE->value;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function mutate(callable $callback): RedirectResponse
    {
        try {
            $callback();
        } catch (KitchenException $exception) {
            return back()->withErrors(['kitchen' => $exception->getMessage()]);
        }

        return back();
    }
}
