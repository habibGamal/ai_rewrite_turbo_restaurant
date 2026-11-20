<?php

namespace App\Terminals;

use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Terminal;
use App\Models\TerminalSession;
use App\Services\FeatureService;

/**
 * «فصل منافذ البيع»: while the vendor turns it on (multiple terminals on
 * too), every sales point works like a separate branch of the same brand
 * on one system. Its orders issue stock from its own warehouse, and only
 * it may pay, edit, cancel or return them. Web orders belong to the sales
 * point picked in the vendor console (default sales point when unset).
 */
class TerminalSeparation
{
    public function __construct(
        private readonly FeatureService $features,
    ) {}

    public function enabled(): bool
    {
        return $this->features->terminalSeparationEnabled();
    }

    /**
     * The sales point web orders belong to.
     */
    public function webOrdersTerminal(): Terminal
    {
        $id = (int) setting(SettingKey::WEB_ORDERS_TERMINAL_ID);

        $terminal = $id > 0 ? Terminal::query()->active()->find($id) : null;

        if ($terminal !== null) {
            return $terminal;
        }

        // Never route web orders to a switched-off sales point.
        $default = Terminal::default();

        return $default->is_active
            ? $default
            : (Terminal::query()->active()->orderBy('sort')->orderBy('id')->first() ?? $default);
    }

    /**
     * Put a new web order on the web-orders sales point, and its money rows
     * on that sales point's open drawer session of the shift (when open).
     */
    public function assignWebOrder(int $shiftId): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        $terminal = $this->webOrdersTerminal();

        $session = TerminalSession::query()
            ->where('shift_id', $shiftId)
            ->where('terminal_id', $terminal->id)
            ->open()
            ->latest('id')
            ->first();

        if ($session !== null) {
            app(TerminalSessionResolver::class)->setCurrent($session);
        }

        return $terminal->id;
    }

    /**
     * Whether the current device may see or act on an order: always while
     * sales points are not separated; otherwise only from the order's own
     * sales point. Requests with no bound device (admin panel, jobs) are not
     * limited.
     */
    public function canAct(Order $order): bool
    {
        if ($order->terminal_id === null || ! $this->enabled()) {
            return true;
        }

        $terminal = app(TerminalSessionResolver::class)->terminal();

        return $terminal === null || $terminal->id === (int) $order->terminal_id;
    }

    public function denialMessage(Order $order): string
    {
        $name = Terminal::query()->whereKey($order->terminal_id)->value('name');

        return 'هذا الطلب يتبع منفذ البيع «'.($name ?? '—').'»، ولا يمكن التعامل معه من منفذ بيع آخر.';
    }
}
