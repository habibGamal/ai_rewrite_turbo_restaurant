<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\Products\PosProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side product search for cashier screens whose catalogue is too
 * big to load at once (see PosProductSearchService::REMOTE_THRESHOLD).
 */
class PosProductController extends Controller
{
    public function __construct(
        private readonly PosProductSearchService $search,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'products' => $this->search->search(
                $validated['type'],
                $validated['q'] ?? null,
                isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            )->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        return response()->json([
            'products' => $this->search->byIds($validated['type'], array_map('intval', $validated['ids']))->values(),
        ]);
    }
}
