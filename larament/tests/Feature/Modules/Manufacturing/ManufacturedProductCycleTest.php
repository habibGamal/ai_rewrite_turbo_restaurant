<?php

namespace Tests\Feature\Filament;

use App\Enums\ProductType;
use App\Filament\Pages\Settings;
use App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\Pages\CreateManufacturedProduct;
use App\Modules\Manufacturing\Filament\Resources\ManufacturedProducts\Pages\EditManufacturedProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManufacturedProductCycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);

        $this->category = Category::factory()->create();
    }

    public function test_settings_page_can_run_cycle_diagnosis_and_detect_cycles(): void
    {
        // Create a manufactured product that has a self-cycle
        $product = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Self Cycled Dish',
        ]);

        ProductComponent::create([
            'product_id' => $product->id,
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        Livewire::test(Settings::class)
            ->call('runCycleDiagnosis')
            ->assertSet('cycleDiagnosisResult.checked_count', 1)
            ->assertCount('cycleDiagnosisResult.cycles', 1)
            ->assertSee('Self Cycled Dish')
            ->assertSee('إشارة ذاتية مباشرة')
            ->assertNotified();
    }

    public function test_settings_page_can_fix_self_referencing_cycles(): void
    {
        $product = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Self Cycled Dish',
        ]);

        ProductComponent::create([
            'product_id' => $product->id,
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('product_components', [
            'product_id' => $product->id,
            'component_id' => $product->id,
        ]);

        Livewire::test(Settings::class)
            ->call('fixCyclicDependenciesAction')
            ->assertSet('cycleDiagnosisResult.checked_count', 1)
            ->assertCount('cycleDiagnosisResult.cycles', 0)
            ->assertNotified();

        $this->assertDatabaseMissing('product_components', [
            'product_id' => $product->id,
            'component_id' => $product->id,
        ]);
    }

    public function test_prevent_creating_manufactured_product_with_cyclic_components(): void
    {
        $componentA = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Component A',
        ]);

        $componentB = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Component B',
        ]);

        // A -> B -> A
        ProductComponent::create([
            'product_id' => $componentA->id,
            'component_id' => $componentB->id,
            'quantity' => 1,
        ]);
        ProductComponent::create([
            'product_id' => $componentB->id,
            'component_id' => $componentA->id,
            'quantity' => 1,
        ]);

        Livewire::test(CreateManufacturedProduct::class)
            ->fillForm([
                'name' => 'New Product',
                'category_id' => $this->category->id,
                'price' => 100,
                'cost' => 50,
                'productComponents' => [
                    [
                        'component_id' => $componentA->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['productComponents']);

        $this->assertDatabaseMissing('products', ['name' => 'New Product']);
    }

    public function test_prevent_editing_manufactured_product_to_introduce_cycle(): void
    {
        $product = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Main Product',
        ]);

        $comp = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Sub Product',
        ]);

        // Sub product already uses Main Product
        ProductComponent::create([
            'product_id' => $comp->id,
            'component_id' => $product->id,
            'quantity' => 1,
        ]);

        // Trying to make Main Product use Sub Product (cycle: Main -> Sub -> Main)
        Livewire::test(EditManufacturedProduct::class, ['record' => $product->id])
            ->fillForm([
                'productComponents' => [
                    [
                        'component_id' => $comp->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['productComponents']);

        $this->assertDatabaseMissing('product_components', [
            'product_id' => $product->id,
            'component_id' => $comp->id,
        ]);
    }

    public function test_prevent_editing_manufactured_product_to_reference_itself(): void
    {
        $product = Product::factory()->create([
            'type' => ProductType::Manufactured,
            'category_id' => $this->category->id,
            'name' => 'Self Referencing Product',
        ]);

        Livewire::test(EditManufacturedProduct::class, ['record' => $product->id])
            ->fillForm([
                'productComponents' => [
                    [
                        'component_id' => $product->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['productComponents']);

        $this->assertDatabaseMissing('product_components', [
            'product_id' => $product->id,
            'component_id' => $product->id,
        ]);
    }
}
