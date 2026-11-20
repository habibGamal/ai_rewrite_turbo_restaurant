<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductComponent;
use Illuminate\Support\Facades\DB;

class RecipeCycleDetectionService
{
    /**
     * Check if assigning the given component IDs to a product would introduce a cyclic dependency.
     *
     * @param  int|null  $productId  The product being created (null) or updated (int).
     * @param  array<int|string>  $componentIds  The list of component product IDs to be assigned.
     * @return array<int, string>|null Returns the cycle path as an array of product names with IDs if a cycle exists, or null if safe.
     */
    public function detectCycleForProduct(?int $productId, array $componentIds): ?array
    {
        $componentIds = array_values(array_filter(array_map('intval', $componentIds)));

        if (empty($componentIds)) {
            return null;
        }

        // 1. Direct self-reference check
        if ($productId !== null && in_array($productId, $componentIds, true)) {
            $product = Product::find($productId);
            $name = $product ? "{$product->name} (#{$productId})" : "منتج #{$productId}";

            return [$name, $name];
        }

        // 2. Build adjacency list of existing product components
        $allComponents = ProductComponent::query()
            ->select('product_id', 'component_id')
            ->get();

        $graph = [];
        foreach ($allComponents as $pc) {
            $graph[(int) $pc->product_id][] = (int) $pc->component_id;
        }

        // Override or inject the proposed components
        $startNode = $productId ?? 0;
        $graph[$startNode] = $componentIds;

        // Run DFS to detect any cycle reachable from the start node
        $color = []; // 0: unvisited, 1: visiting, 2: visited
        $path = [];

        $cyclePath = $this->dfsFindCycle($startNode, $graph, $color, $path);

        if (! empty($cyclePath)) {
            $realIds = array_values(array_filter($cyclePath, fn ($id) => $id > 0));
            $productNames = Product::whereIn('id', $realIds)->pluck('name', 'id')->all();

            return array_map(function ($id) use ($productNames, $productId) {
                if ($id === 0 && $productId === null) {
                    return 'المنتج الجديد';
                }

                $name = $productNames[$id] ?? "منتج #{$id}";

                return "{$name} (#{$id})";
            }, $cyclePath);
        }

        return null;
    }

    /**
     * Find all cycles across all manufactured products in the database.
     *
     * @return array<int, array{product_id: int, product_name: string, cycle_path: array<int, string>, cycle_ids: array<int, int>, is_self: bool}>
     */
    public function findAllCycles(): array
    {
        $allComponents = ProductComponent::with(['product:id,name,type', 'component:id,name,type'])
            ->get();

        $graph = [];
        $productNames = [];

        foreach ($allComponents as $pc) {
            $pId = (int) $pc->product_id;
            $cId = (int) $pc->component_id;
            $graph[$pId][] = $cId;

            if ($pc->product instanceof Product) {
                $productNames[$pId] = $pc->product->name;
            }
            if ($pc->component instanceof Product) {
                $productNames[$cId] = $pc->component->name;
            }
        }

        // Also check any missing product names
        $allReferencedIds = array_unique(array_merge(array_keys($graph), ...array_values($graph)));
        $missingIds = array_diff($allReferencedIds, array_keys($productNames));
        if (! empty($missingIds)) {
            $extra = Product::whereIn('id', $missingIds)->pluck('name', 'id')->all();
            $productNames += $extra;
        }

        $cycles = [];
        $seenCycleKeys = [];

        // 1. Direct self-references first
        foreach ($graph as $node => $neighbors) {
            if (in_array($node, $neighbors, true)) {
                $nodeName = ($productNames[$node] ?? "منتج #{$node}")." (#{$node})";
                $key = "{$node}-{$node}";
                $seenCycleKeys[$key] = true;

                $cycles[] = [
                    'product_id' => $node,
                    'product_name' => $productNames[$node] ?? "منتج #{$node}",
                    'cycle_path' => [$nodeName, $nodeName],
                    'cycle_ids' => [$node, $node],
                    'is_self' => true,
                ];
            }
        }

        // 2. Multi-node cycles
        $color = [];
        foreach (array_keys($graph) as $node) {
            if (! isset($color[$node])) {
                $path = [];
                $foundCycles = $this->collectAllCyclesFromNode($node, $graph, $color, $path);
                foreach ($foundCycles as $cycleIds) {
                    // Check if self-loop already captured
                    if (count($cycleIds) === 2 && $cycleIds[0] === $cycleIds[1]) {
                        continue;
                    }

                    // Canonical key to prevent duplicates: find min element and rotate
                    $core = array_slice($cycleIds, 0, -1);
                    $minVal = min($core);
                    $minIdx = array_search($minVal, $core, true);
                    $canonical = array_merge(array_slice($core, $minIdx), array_slice($core, 0, $minIdx));
                    $key = implode('-', $canonical);

                    if (isset($seenCycleKeys[$key])) {
                        continue;
                    }
                    $seenCycleKeys[$key] = true;

                    $startId = $cycleIds[0];
                    $cyclePathNames = array_map(
                        fn ($id) => ($productNames[$id] ?? "منتج #{$id}")." (#{$id})",
                        $cycleIds
                    );

                    $cycles[] = [
                        'product_id' => $startId,
                        'product_name' => $productNames[$startId] ?? "منتج #{$startId}",
                        'cycle_path' => $cyclePathNames,
                        'cycle_ids' => $cycleIds,
                        'is_self' => false,
                    ];
                }
            }
        }

        return $cycles;
    }

    /**
     * Remove self-referencing components (WHERE product_id = component_id).
     * Returns count of removed rows.
     */
    public function fixSelfReferencingComponents(): int
    {
        return DB::transaction(function () {
            return ProductComponent::whereColumn('product_id', 'component_id')->delete();
        });
    }

    /**
     * DFS helper to find a cycle in a directed graph.
     *
     * @param  array<int, array<int>>  $graph
     * @param  array<int, int>  $color
     * @param  array<int, int>  $path
     * @return array<int, int>|null
     */
    private function dfsFindCycle(int $node, array &$graph, array &$color, array &$path): ?array
    {
        $color[$node] = 1; // visiting
        $path[] = $node;

        foreach ($graph[$node] ?? [] as $neighbor) {
            $neighborColor = $color[$neighbor] ?? 0;

            if ($neighborColor === 1) {
                // Cycle detected!
                $startIndex = array_search($neighbor, $path, true);
                if ($startIndex !== false) {
                    $cycle = array_slice($path, $startIndex);
                    $cycle[] = $neighbor;

                    return $cycle;
                }

                return [$node, $neighbor];
            }

            if ($neighborColor === 0) {
                $cycle = $this->dfsFindCycle($neighbor, $graph, $color, $path);
                if ($cycle !== null) {
                    return $cycle;
                }
            }
        }

        $color[$node] = 2; // visited
        array_pop($path);

        return null;
    }

    /**
     * DFS helper to collect cycles from a given node.
     *
     * @param  array<int, array<int>>  $graph
     * @param  array<int, int>  $color
     * @param  array<int, int>  $path
     * @return array<int, array<int, int>>
     */
    private function collectAllCyclesFromNode(int $node, array &$graph, array &$color, array &$path): array
    {
        $color[$node] = 1;
        $path[] = $node;
        $cycles = [];

        foreach ($graph[$node] ?? [] as $neighbor) {
            $neighborColor = $color[$neighbor] ?? 0;

            if ($neighborColor === 1) {
                $startIndex = array_search($neighbor, $path, true);
                if ($startIndex !== false) {
                    $cycle = array_slice($path, $startIndex);
                    $cycle[] = $neighbor;
                    $cycles[] = $cycle;
                }
            } elseif ($neighborColor === 0) {
                $subCycles = $this->collectAllCyclesFromNode($neighbor, $graph, $color, $path);
                foreach ($subCycles as $sc) {
                    $cycles[] = $sc;
                }
            }
        }

        $color[$node] = 2;
        array_pop($path);

        return $cycles;
    }
}
