<?php

use App\Enums\OptionalFeature;
use App\Models\ExternalSeller;
use App\Models\ExternalSellerSettlement;
use App\Models\Shift;
use App\Models\User;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('defaults all four optional feature flags to false on a fresh install and rolls back cleanly', function () {
    $migration = require database_path('migrations/2026_11_10_400001_add_optional_feature_settings_and_audits.php');

    $migration->down();

    expect(Schema::hasTable('feature_flag_audits'))->toBeFalse()
        ->and(DB::table('settings')->whereIn('key', [
            'feature_revenues_enabled',
            'feature_custom_fees_enabled',
            'feature_outsourced_orders_enabled',
            'feature_equipment_enabled',
        ])->count())->toBe(0);

    $migration->up();

    expect(Schema::hasTable('feature_flag_audits'))->toBeTrue()
        ->and(DB::table('settings')->where('key', 'feature_revenues_enabled')->value('value'))->toBe('false')
        ->and(DB::table('settings')->where('key', 'feature_custom_fees_enabled')->value('value'))->toBe('false')
        ->and(DB::table('settings')->where('key', 'feature_outsourced_orders_enabled')->value('value'))->toBe('false')
        ->and(DB::table('settings')->where('key', 'feature_equipment_enabled')->value('value'))->toBe('false')
        ->and(app(FeatureService::class)->all())->toBe([
            'feature_revenues_enabled' => false,
            'feature_custom_fees_enabled' => false,
            'feature_outsourced_orders_enabled' => false,
            'feature_equipment_enabled' => false,
            // Added later (variants, 2026-11-17) with no settings row: falls back to its default.
            'feature_variants_enabled' => false,
            'feature_multi_terminal_enabled' => false,
            'feature_purchase_barcodes_enabled' => false,
            'feature_label_printing_enabled' => false,
            'feature_document_barcodes_enabled' => false,
            'feature_deferred_payment_enabled' => false,
            'feature_returns_enabled' => false,
            'feature_terminal_separation_enabled' => false,
            'feature_closed_shift_edits_enabled' => false,
            'feature_ai_assistant_enabled' => false,
            'feature_ai_queries_enabled' => false,
        ]);
});

it('enables outsourced orders on upgrade when existing external sellers exist while keeping new features false', function () {
    $migration = require database_path('migrations/2026_11_10_400001_add_optional_feature_settings_and_audits.php');

    $migration->down();

    ExternalSeller::create([
        'name' => 'متجر الحلويات المتعاون',
        'phone' => '01000000001',
        'is_active' => true,
    ]);

    $migration->up();

    $featureService = app(FeatureService::class);

    expect(DB::table('settings')->where('key', 'feature_outsourced_orders_enabled')->value('value'))->toBe('true')
        ->and(DB::table('settings')->where('key', 'feature_revenues_enabled')->value('value'))->toBe('false')
        ->and(DB::table('settings')->where('key', 'feature_custom_fees_enabled')->value('value'))->toBe('false')
        ->and(DB::table('settings')->where('key', 'feature_equipment_enabled')->value('value'))->toBe('false')
        ->and($featureService->isEnabled(OptionalFeature::OUTSOURCED_ORDERS))->toBeTrue()
        ->and($featureService->isEnabled(OptionalFeature::REVENUES))->toBeFalse()
        ->and($featureService->isEnabled(OptionalFeature::CUSTOM_FEES))->toBeFalse()
        ->and($featureService->isEnabled(OptionalFeature::EQUIPMENT))->toBeFalse();
});

it('enables outsourced orders on upgrade when historical settlements exist', function () {
    $migration = require database_path('migrations/2026_11_10_400001_add_optional_feature_settings_and_audits.php');

    $user = User::factory()->admin()->create();
    $shift = Shift::factory()->active()->create(['user_id' => $user->id]);
    $seller = ExternalSeller::create(['name' => 'بائع تاريخي', 'is_active' => false]);

    ExternalSellerSettlement::create([
        'external_seller_id' => $seller->id,
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'amount' => 50,
        'note' => 'تسوية سابقة',
    ]);

    $migration->down();
    $migration->up();

    expect(DB::table('settings')->where('key', 'feature_outsourced_orders_enabled')->value('value'))->toBe('true')
        ->and(app(FeatureService::class)->outsourcedOrdersEnabled())->toBeTrue();
});
