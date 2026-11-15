<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\OrderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InstantSaleRequest;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * F8 / F9 on the instant-sale screen. A held cart is not an order, so no
 * number, stock or payment is touched until it is resumed and paid.
 */
class HeldSaleController extends Controller
{
    public function index(ShiftService $shifts): JsonResponse
    {
        $shift = $this->shift($shifts);

        $held = HeldSale::query()
            ->where('shift_id', $shift)
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn (HeldSale $sale): array => $this->payload($sale))
            ->all();

        return response()->json(['data' => $held]);
    }

    public function store(Request $request, ShiftService $shifts): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'exists:order_types,code'],
            'label' => ['nullable', 'string', 'max:100'],
            ...InstantSaleRequest::itemRules('items'),
            'meta' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User && $user->canSeeTab('type:'.$validated['type']), 403);

        $lines = InstantSaleRequest::lines($validated['items']);
        $prices = Product::query()->whereIn('id', array_column($lines, 'product_id'))->pluck('price', 'id');
        $total = 0.0;
        foreach ($lines as $line) {
            $total += (float) ($prices[(int) $line['product_id']] ?? 0) * (float) $line['quantity'];
        }

        $sale = HeldSale::query()->create([
            'shift_id' => $this->shift($shifts),
            'user_id' => $user->id,
            'order_type' => $validated['type'],
            'label' => $validated['label'] ?? null,
            'lines' => $lines,
            'meta' => $validated['meta'] ?? null,
            'total' => round($total, 2),
        ]);

        return response()->json(['data' => $this->payload($sale->load('user:id,name'))], 201);
    }

    public function destroy(HeldSale $heldSale, ShiftService $shifts): Response
    {
        abort_unless($heldSale->shift_id === $this->shift($shifts), 404);

        $heldSale->delete();

        return response()->noContent();
    }

    private function shift(ShiftService $shifts): int
    {
        $shift = $shifts->getCurrentShift();

        if ($shift === null) {
            throw new OrderException('لا يوجد شيفت مفتوح');
        }

        return $shift->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(HeldSale $sale): array
    {
        $user = $sale->user;

        return [
            'id' => $sale->id,
            'type' => $sale->order_type,
            'label' => $sale->label,
            'lines' => $sale->lines,
            'meta' => $sale->meta,
            'total' => (float) $sale->total,
            'user' => $user instanceof User ? ['id' => $user->id, 'name' => $user->name] : null,
            'created_at' => $sale->created_at,
        ];
    }
}
