<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Services\Products\ProductLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolves a scanned code for the POS. The POS only adds the product if it is
 * on the order type's menu, so the server answers with the id and quantity.
 */
class ProductLookupController extends Controller
{
    public function __invoke(Request $request, ProductLookupService $lookup): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        // Raw materials are never sold at the POS.
        $result = $lookup->lookup($validated['code'], [ProductType::Manufactured, ProductType::Consumable]);

        if ($result === null) {
            return response()->json(['found' => false], 404);
        }

        return response()->json(['found' => true] + $result->toArray());
    }
}
