<?php

use App\Enums\OptionalFeature;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyExpensesTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyRevenuesTable;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\ShiftMoneyService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->features = app(FeatureService::class);
    $this->service = app(ShiftMoneyService::class);
    $this->type = ExpenceType::factory()->create();
    $this->closed = Shift::factory()->create([
        'closed' => true,
        'start_at' => now()->subHours(8),
        'end_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
        'start_cash' => 100,
        'end_cash' => 100,
    ]);
    $this->expense = Expense::factory()->create(['shift_id' => $this->closed->id, 'expence_type_id' => $this->type->id, 'amount' => 10]);
});

it('starts off on a fresh install as a standalone vendor feature', function () {
    expect($this->features->closedShiftEditsEnabled())->toBeFalse()
        ->and(OptionalFeature::standalone())->toContain(OptionalFeature::CLOSED_SHIFT_EDITS)
        ->and(OptionalFeature::CLOSED_SHIFT_EDITS->permissions())->toBe([ShiftMoneyService::PERMISSION_EDIT_CLOSED]);
});

describe('off', function () {
    it('removes the closed-shift permission even from the admin and the vendor', function () {
        expect($this->features->isPermissionDisabled(ShiftMoneyService::PERMISSION_EDIT_CLOSED))->toBeTrue()
            ->and($this->admin->can(ShiftMoneyService::PERMISSION_EDIT_CLOSED))->toBeFalse()
            ->and($this->features->disabledFeaturePermissions())->toContain(ShiftMoneyService::PERMISSION_EDIT_CLOSED);

        $vendor = User::factory()->vendor()->create();
        expect($vendor->can(ShiftMoneyService::PERMISSION_EDIT_CLOSED))->toBeFalse()
            ->and($this->service->canEdit($this->closed, $vendor))->toBeFalse();
    });

    it('keeps open shifts editable but refuses any change to a closed shift', function () {
        $open = Shift::factory()->active()->create();

        expect($this->service->canEdit($open))->toBeTrue()
            ->and($this->service->canEdit($this->closed))->toBeFalse();

        expect(fn () => $this->service->addExpense($this->closed, ['expence_type_id' => $this->type->id, 'amount' => 5], 'سبب'))
            ->toThrow(AuthorizationException::class, OptionalFeature::CLOSED_SHIFT_EDITS->disabledErrorMessage());
        expect(fn () => $this->service->deleteExpense($this->expense, 'سبب'))
            ->toThrow(AuthorizationException::class);

        expect(Expense::query()->count())->toBe(1);
    });

    it('shows a closed shift read-only on the shift money page', function () {
        $this->features->enable(OptionalFeature::REVENUES);

        livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $this->closed->id]])
            ->assertCanSeeTableRecords([$this->expense])
            ->assertActionHidden(TestAction::make('addExpense')->table())
            ->assertActionHidden(TestAction::make('deleteExpense')->table($this->expense));

        livewire(ShiftMoneyRevenuesTable::class, ['pageFilters' => ['shift_id' => $this->closed->id]])
            ->assertActionHidden(TestAction::make('addRevenue')->table());
    });
});

it('lets the admin edit a closed shift once the vendor turns it on', function () {
    $this->features->enable(OptionalFeature::CLOSED_SHIFT_EDITS);

    expect($this->service->canEdit($this->closed))->toBeTrue();

    $this->service->addExpense($this->closed, ['expence_type_id' => $this->type->id, 'amount' => 5], 'سبب');

    expect(Expense::query()->count())->toBe(2)
        ->and($this->closed->fresh()->money_edited_at)->not->toBeNull();

    livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $this->closed->id]])
        ->assertActionVisible(TestAction::make('addExpense')->table());
});

describe('upgrade migration', function () {
    function runClosedShiftEditsMigration(): string
    {
        $migration = require database_path('migrations/2026_12_03_000001_add_closed_shift_edits_feature.php');
        $migration->down();
        $migration->up();

        return (string) DB::table('settings')->where('key', 'feature_closed_shift_edits_enabled')->value('value');
    }

    it('stays off when no closed shift was ever edited', function () {
        expect(runClosedShiftEditsMigration())->toBe('false');
    });

    it('turns on when a closed shift was already edited', function () {
        $this->closed->forceFill(['money_edited_at' => now(), 'money_edited_count' => 1])->save();

        expect(runClosedShiftEditsMigration())->toBe('true');
    });
});
