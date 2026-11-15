<?php

use App\Enums\OptionalFeature;
use App\Enums\PaymentMethod;
use App\Enums\RevenueMethod;
use App\Filament\Resources\RevenueTypes\Pages\CreateRevenueType;
use App\Filament\Resources\RevenueTypes\Pages\EditRevenueType;
use App\Filament\Resources\RevenueTypes\Pages\ListRevenueTypes;
use App\Filament\Resources\RevenueTypes\RevenueTypeResource;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ShiftsReportService;
use App\Sync\BackfillService;
use App\Sync\Builders\DaySummaryBuilder;
use App\Sync\Builders\RevenueBuilder;
use App\Sync\Builders\RevenueTypeBuilder;
use App\Sync\DocumentType;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
});

describe('revenue types screen', function () {
    it('is hidden and answers 404 while the feature is off', function () {
        app(FeatureService::class)->disable(OptionalFeature::REVENUES);

        expect(RevenueTypeResource::canAccess())->toBeFalse()
            ->and(RevenueTypeResource::shouldRegisterNavigation())->toBeFalse();

        $this->get(RevenueTypeResource::getUrl('index'))->assertNotFound();
    });

    it('lets the admin create, edit and list revenue types when on', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);

        expect(RevenueTypeResource::canAccess())->toBeTrue();
        $this->get(RevenueTypeResource::getUrl('index'))->assertOk();

        livewire(CreateRevenueType::class)
            ->fillForm(['name' => 'إيجار قاعة', 'sort_order' => 2, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = RevenueType::query()->where('name', 'إيجار قاعة')->firstOrFail();
        expect($type->uuid)->not->toBeEmpty()
            ->and($type->sort_order)->toBe(2);

        livewire(EditRevenueType::class, ['record' => $type->id])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($type->fresh()->is_active)->toBeFalse();

        livewire(ListRevenueTypes::class)->assertCanSeeTableRecords([$type]);
    });

    it('rejects a duplicate name', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        RevenueType::factory()->create(['name' => 'بيع كرتون']);

        livewire(CreateRevenueType::class)
            ->fillForm(['name' => 'بيع كرتون', 'sort_order' => 0])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    });

    it('cannot delete a type that already has revenues, only an unused one', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        $used = RevenueType::factory()->create();
        Revenue::factory()->create(['revenue_type_id' => $used->id]);
        $unused = RevenueType::factory()->create();

        livewire(ListRevenueTypes::class)
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($used))
            ->assertActionVisible(TestAction::make(DeleteAction::class)->table($unused))
            ->callAction(TestAction::make(DeleteAction::class)->table($unused));

        expect(RevenueType::query()->whereKey($unused->id)->exists())->toBeFalse()
            ->and(RevenueType::query()->whereKey($used->id)->exists())->toBeTrue();
    });

    it('gates the revenue type permissions behind the feature', function () {
        $features = app(FeatureService::class);
        $features->disable(OptionalFeature::REVENUES);

        expect($features->disabledFeaturePermissions())->toContain('view_revenue_types', 'create_revenue_types')
            ->and($this->admin->can('view_revenue_types'))->toBeFalse(); // kept on the role, denied while off

        $features->enable(OptionalFeature::REVENUES);
        expect($features->disabledFeaturePermissions())->not->toContain('view_revenue_types')
            ->and($this->admin->fresh()->can('view_revenue_types'))->toBeTrue();
    });

    it('does not give the cashier any revenue permission', function () {
        $cashier = User::factory()->create();
        $cashier->syncRoles(['cashier']);

        expect($cashier->can('view_revenue_types'))->toBeFalse()
            ->and($cashier->can('create_revenue_types'))->toBeFalse();
    });
});

describe('shift stats', function () {
    it('adds cash revenues to available cash and reports card revenues apart', function () {
        $shift = Shift::factory()->create(['start_cash' => 100, 'closed' => false, 'end_at' => null]);
        $other = Shift::factory()->create();
        Payment::factory()->create(['shift_id' => $shift->id, 'amount' => 50, 'method' => PaymentMethod::CASH]);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id, 'amount' => 30]);
        Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 200, 'method' => RevenueMethod::CASH]);
        Revenue::factory()->card()->create(['shift_id' => $shift->id, 'amount' => 75]);
        Revenue::factory()->create(['shift_id' => $other->id, 'amount' => 999]);

        $stats = app(ShiftsReportService::class)->calculateShiftStats($shift);

        expect($stats['revenues'])->toBe(275.0)
            ->and($stats['cashRevenues'])->toBe(200.0)
            ->and($stats['cardRevenues'])->toBe(75.0)
            ->and((float) $stats['availableCash'])->toBe(100.0 + 50 + 200 - 30);
    });

    it('sums revenues over the selected shifts of a period', function () {
        $a = Shift::factory()->create(['start_cash' => 0]);
        $b = Shift::factory()->create(['start_cash' => 0]);
        $outside = Shift::factory()->create(['start_cash' => 0]);
        Revenue::factory()->create(['shift_id' => $a->id, 'amount' => 10]);
        Revenue::factory()->card()->create(['shift_id' => $b->id, 'amount' => 20]);
        Revenue::factory()->create(['shift_id' => $outside->id, 'amount' => 500]);

        $stats = app(ShiftsReportService::class)->calculatePeriodStats(null, null, [$a->id, $b->id]);

        expect($stats['revenues'])->toBe(30.0)
            ->and($stats['cashRevenues'])->toBe(10.0)
            ->and($stats['cardRevenues'])->toBe(20.0)
            ->and((float) $stats['availableCash'])->toBe(10.0);
    });

    it('closes a shift with cash revenues counted in the expected cash', function () {
        $shift = Shift::factory()->create(['user_id' => $this->admin->id, 'start_cash' => 100, 'closed' => false, 'end_at' => null]);
        Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 40]);
        Revenue::factory()->card()->create(['shift_id' => $shift->id, 'amount' => 60]);

        expect((float) app(ShiftsReportService::class)->calculateShiftStats($shift)['availableCash'])->toBe(140.0);
    });
});

describe('sync', function () {
    it('builds revenue and revenue type documents', function () {
        pairPlatform();
        $shift = Shift::factory()->create();
        $type = RevenueType::factory()->create(['name' => 'إيجار قاعة', 'sort_order' => 3]);
        $revenue = Revenue::factory()->card()->create([
            'shift_id' => $shift->id,
            'revenue_type_id' => $type->id,
            'user_id' => $this->admin->id,
            'amount' => 150.5,
            'notes' => 'حفلة',
        ]);

        expect(app(RevenueTypeBuilder::class)->build($type->uuid))->toBe([
            'name' => 'إيجار قاعة',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $doc = app(RevenueBuilder::class)->build($revenue->uuid);
        expect($doc['shift_uuid'])->toBe($shift->uuid)
            ->and($doc['revenue_type_uuid'])->toBe($type->uuid)
            ->and($doc['user_uuid'])->toBe($this->admin->uuid)
            ->and($doc['amount'])->toBe('150.50')
            ->and($doc['method'])->toBe('card')
            ->and($doc['notes'])->toBe('حفلة');
    });

    it('queues revenues and revenue types in the outbox when paired', function () {
        pairPlatform();
        $type = RevenueType::factory()->create();
        $revenue = Revenue::factory()->create(['revenue_type_id' => $type->id]);

        expect(SyncOutbox::query()->where('doc_type', DocumentType::RevenueType->value)->where('doc_uuid', $type->uuid)->exists())->toBeTrue()
            ->and(SyncOutbox::query()->where('doc_type', DocumentType::Revenue->value)->where('doc_uuid', $revenue->uuid)->exists())->toBeTrue();

        $uuid = $revenue->uuid;
        $revenue->delete();
        expect(SyncOutbox::query()->where('doc_type', DocumentType::Revenue->value)->where('doc_uuid', $uuid)->where('op', 'delete')->exists())->toBeTrue();
    });

    it('backfills revenue types', function () {
        $type = RevenueType::factory()->create();
        $connection = pairPlatform();
        SyncOutbox::query()->delete();

        app(BackfillService::class)->queueCatalog($connection);

        expect(SyncOutbox::query()->where('doc_type', 'revenue_type')->where('doc_uuid', $type->uuid)->exists())->toBeTrue();
    });

    it('adds revenues_total to the day summary', function () {
        $shift = Shift::factory()->create(['business_date' => '2026-10-29']);
        Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 25]);
        Revenue::factory()->card()->create(['shift_id' => $shift->id, 'amount' => 5]);

        $data = app(DaySummaryBuilder::class)->withKey('2026-10-29')->build('dummy');

        expect($data['revenues_total'] ?? null)->toBe('30.00');
    });
});
