<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Models\User;
use App\Services\FeatureService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('audit_events')]
#[Title('Audit events')]
#[Description(<<<'TEXT'
Sensitive events, newest first, with who did them: order cancellations, discounts applied, items removed or reduced on saved orders, refunds, and audited record changes (edited expenses, closed-shift money, settings and other tracked records, with the reason given).
Use for "who cancelled orders today", "unusual discounts this week", "who edited the closed shift", "what changed yesterday".
Not for totals per cashier (use staff_performance).
Examples: «من ألغى طلبات اليوم؟», «ما التعديلات التي تمت على الورديات المغلقة هذا الأسبوع؟», "Show discounts above 100 applied yesterday".
TEXT)]
#[IsReadOnly]
class AuditEventsTool extends BusinessTool
{
    public const TYPES = ['cancellation', 'discount', 'item_removed', 'refund', 'record_change'];

    private readonly FeatureService $features;

    /** @var array<int, string> */
    private array $names = [];

    /** Services come from the container when the tool is built without them. */
    public function __construct(?FeatureService $features = null)
    {
        $this->features = $features ?? app(FeatureService::class);
    }

    protected function reportPages(): array
    {
        return [];
    }

    public function allowed(): bool
    {
        return Auth::user() !== null && AuditLogResource::canViewAny();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_types' => $schema->array()->items($schema->string()->enum(self::TYPES))->description('Default: all.'),
            'staff' => $schema->string()->description('Only events by this staff member (name or id).'),
            ...$this->periodSchema($schema, compare: false, default: 'today'),
            'limit' => $schema->integer()->description('Events to return, newest first. Default 30, max 100.'),
        ];
    }

    protected function run(Request $request): array
    {
        $period = $this->period($request, 'today', 'none');
        if ($period->days() > 93) {
            throw new InvalidArgumentException('Audit events cover at most 93 days. Ask for a shorter period.');
        }
        $range = [Carbon::parse($period->from->toDateString())->startOfDay(), Carbon::parse($period->to->toDateString())->endOfDay()];
        $limit = $this->limit($request, 30);

        $types = array_values(array_intersect(self::TYPES, (array) ($request->get('event_types') ?? self::TYPES)));
        if (! $this->features->returnsEnabled()) {
            $types = array_values(array_diff($types, ['refund']));
        }

        $staff = trim((string) ($request->get('staff') ?? ''));
        $staffIds = $staff === '' ? null : (ctype_digit($staff)
            ? [(int) $staff]
            : User::query()->withoutGlobalScopes()->where('name', 'like', '%'.$staff.'%')->pluck('id')->all());

        $events = [];
        $counts = [];
        foreach ($types as $type) {
            $query = $this->source($type, $range);
            if ($staffIds !== null) {
                $query->whereIn('ev_user', $staffIds);
            }
            $counts[$type] = (clone $query)->count();
            foreach ((clone $query)->orderByDesc('ev_at')->limit($limit)->get() as $row) {
                $events[] = $this->event($type, $row);
            }
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $this->envelope($period, [
            'counts' => $counts,
            'events' => array_slice($events, 0, $limit),
            'truncated' => array_sum($counts) > $limit,
        ], [
            'item_removed' => 'An item removed, or its quantity lowered, on an order that was already saved.',
            'record_change' => 'A change written to the audit log, with old and new values and the reason.',
        ], [], array_filter(['event_types' => $request->get('event_types'), 'staff' => $staff ?: null]));
    }

    /** @param  array{0: Carbon, 1: Carbon}  $range */
    private function source(string $type, array $range): QueryBuilder
    {
        $query = match ($type) {
            'cancellation', 'discount' => DB::table('order_histories as h')
                ->where('h.action', $type === 'cancellation' ? 'cancelled' : 'discount_applied')
                ->select('h.order_id', 'h.description', 'h.details')
                ->selectRaw('h.user_id as ev_user, h.created_at as ev_at'),
            'item_removed' => DB::table('order_item_changes as c')
                ->where(fn ($q) => $q->where('c.change_type', 'removed')->orWhere('c.delta', '<', 0))
                ->select('c.order_id', 'c.product_name', 'c.old_quantity', 'c.new_quantity', 'c.unit_price')
                ->selectRaw('c.user_id as ev_user, c.created_at as ev_at'),
            'refund' => DB::table('order_returns as r')
                ->select('r.order_id', 'r.total_refund', 'r.reason')
                ->selectRaw('r.user_id as ev_user, r.created_at as ev_at'),
            default => DB::table('audit_logs as a')
                ->select('a.id', 'a.auditable_type', 'a.auditable_id', 'a.action', 'a.reason', 'a.context', 'a.old_values', 'a.new_values')
                ->selectRaw('a.user_id as ev_user, a.created_at as ev_at'),
        };

        return DB::query()->fromSub($query, 'ev')->whereBetween('ev_at', $range);
    }

    /** @return array<string, mixed> */
    private function event(string $type, object $row): array
    {
        $base = ['type' => $type, 'at' => substr((string) $row->ev_at, 0, 16), 'by' => $this->staffName($row->ev_user)];

        return array_filter($base + match ($type) {
            'cancellation', 'discount' => ['order_id' => (int) $row->order_id, 'description' => $row->description, 'details' => $row->details !== null ? json_decode((string) $row->details, true) : null],
            'item_removed' => [
                'order_id' => (int) $row->order_id,
                'product' => $row->product_name,
                'old_quantity' => (float) $row->old_quantity,
                'new_quantity' => (float) $row->new_quantity,
                'value' => round(max(0, (float) $row->old_quantity - (float) $row->new_quantity) * (float) $row->unit_price, 2),
            ],
            'refund' => ['order_id' => (int) $row->order_id, 'refunded' => round((float) $row->total_refund, 2), 'reason' => $row->reason],
            default => [
                'record' => class_basename((string) $row->auditable_type).' #'.$row->auditable_id,
                'action' => $row->action,
                'context' => $row->context,
                'reason' => $row->reason,
                'old' => $row->old_values !== null ? json_decode((string) $row->old_values, true) : null,
                'new' => $row->new_values !== null ? json_decode((string) $row->new_values, true) : null,
            ],
        }, fn ($value) => $value !== null && $value !== '');
    }

    private function staffName(mixed $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $id = (int) $userId;
        if (! array_key_exists($id, $this->names)) {
            $user = User::query()->withoutGlobalScopes()->find($id);
            $this->names[$id] = ! $user instanceof User ? (string) $id : ($user->isVendor() ? User::VENDOR_DISPLAY_NAME : (string) $user->name);
        }

        return $this->names[$id];
    }
}
