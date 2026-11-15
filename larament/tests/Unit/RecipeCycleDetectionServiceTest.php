<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductComponent;
use App\Services\Products\RecipeCycleDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeCycleDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecipeCycleDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RecipeCycleDetectionService::class);
    }

    public function test_detects_direct_self_reference(): void
    {
        $product = Product::factory()->create(['name' => 'Burger']);

        $cycle = $this->service->detectCycleForProduct($product->id, [$product->id]);

        $this->assertNotNull($cycle);
        $this->assertCount(2, $cycle);
        $this->assertStringContainsString('Burger', $cycle[0]);
    }

    public function test_detects_indirect_two_hop_cycle(): void
    {
        $productA = Product::factory()->create(['name' => 'Product A']);
        $productB = Product::factory()->create(['name' => 'Product B']);

        // B has A as component
        ProductComponent::create([
            'product_id' => $productB->id,
            'component_id' => $productA->id,
            'quantity' => 1,
        ]);

        // Attempting to add B as component to A -> cycle A -> B -> A
        $cycle = $this->service->detectCycleForProduct($productA->id, [$productB->id]);

        $this->assertNotNull($cycle);
        $this->assertContains("Product A (#{$productA->id})", $cycle);
        $this->assertContains("Product B (#{$productB->id})", $cycle);
    }

    public function test_detects_multi_hop_cycle(): void
    {
        $productA = Product::factory()->create(['name' => 'A']);
        $productB = Product::factory()->create(['name' => 'B']);
        $productC = Product::factory()->create(['name' => 'C']);

        // B -> C
        ProductComponent::create([
            'product_id' => $productB->id,
            'component_id' => $productC->id,
            'quantity' => 1,
        ]);
        // C -> A
        ProductComponent::create([
            'product_id' => $productC->id,
            'component_id' => $productA->id,
            'quantity' => 1,
        ]);

        // Trying to set A -> B creates A -> B -> C -> A
        $cycle = $this->service->detectCycleForProduct($productA->id, [$productB->id]);

        $this->assertNotNull($cycle);
        $this->assertCount(4, $cycle); // A -> B -> C -> A
    }

    public function test_allows_valid_acyclic_recipe(): void
    {
        $productA = Product::factory()->create(['name' => 'A']);
        $productB = Product::factory()->create(['name' => 'B']);
        $productC = Product::factory()->create(['name' => 'C']);

        // B -> C
        ProductComponent::create([
            'product_id' => $productB->id,
            'component_id' => $productC->id,
            'quantity' => 1,
        ]);

        // A -> B is valid since C does not point back to A
        $cycle = $this->service->detectCycleForProduct($productA->id, [$productB->id]);

        $this->assertNull($cycle);
    }

    public function test_detects_cycle_for_new_product_if_selected_component_has_cycle(): void
    {
        $productB = Product::factory()->create(['name' => 'B']);
        $productC = Product::factory()->create(['name' => 'C']);

        // B -> C -> B
        ProductComponent::create([
            'product_id' => $productB->id,
            'component_id' => $productC->id,
            'quantity' => 1,
        ]);
        ProductComponent::create([
            'product_id' => $productC->id,
            'component_id' => $productB->id,
            'quantity' => 1,
        ]);

        // New product selects B
        $cycle = $this->service->detectCycleForProduct(null, [$productB->id]);

        $this->assertNotNull($cycle);
    }

    public function test_find_all_cycles_and_fix_self_referencing(): void
    {
        $productA = Product::factory()->create(['name' => 'Self Ref']);
        ProductComponent::create([
            'product_id' => $productA->id,
            'component_id' => $productA->id,
            'quantity' => 1,
        ]);

        $cycles = $this->service->findAllCycles();
        $this->assertNotEmpty($cycles);
        $this->assertTrue($cycles[0]['is_self']);
        $this->assertSame($productA->id, $cycles[0]['product_id']);

        $fixed = $this->service->fixSelfReferencingComponents();
        $this->assertSame(1, $fixed);

        $cyclesAfter = $this->service->findAllCycles();
        $this->assertEmpty($cyclesAfter);
    }
}
