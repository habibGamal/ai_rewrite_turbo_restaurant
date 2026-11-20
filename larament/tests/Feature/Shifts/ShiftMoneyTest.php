<?php

use App\Enums\AuditAction;
use App\Enums\OptionalFeature;
use App\Enums\OrderStatus;
use App\Enums\RevenueMethod;
use App\Filament\Pages\Reports\ExpensesReport;
use App\Filament\Pages\ShiftMoney\ShiftMoneyPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\ExpensesRevenuesSummaryWidget;
use App\Filament\Widgets\PeriodShiftExpensesDetailsTable;
use App\Filament\Widgets\PeriodShiftExpensesTable;
use App\Filament\Widgets\PeriodShiftRevenuesDetailsTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyExpensesTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneyRevenuesTable;
use App\Filament\Widgets\ShiftMoney\ShiftMoneySummaryStats;
use App\Models\AuditLog;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Revenue;
use App\Models\RevenueType;
use App\Models\Shift;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Services\FeatureService;
use App\Services\Reports\ExpensesRevenuesReportService;
use App\Services\ShiftMoneyService;
use App\Services\ShiftsReportService;
use App\Sync\DocumentType;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    actingAs($this->admin);
    $this->service = app(ShiftMoneyService::class);
    $this->type = ExpenceType::factory()->create();
});

/**
 * A closed shift whose recorded figures match the formula: start 100 + cash
 * revenue 50 − expense 30 = 120 expected; the cashier counted 115 (shortage 5).
 */
function closedShiftWithMoney(): Shift
{
    $shift = Shift::factory()->create([
        'start_cash' => 100,
        'closed' => true,
        'start_at' => now()->subHours(8),
        'end_at' => now()->subHour(),
        'business_date' => now()->toDateString(),
        'end_cash' => 120,
        'real_cash' => 115,
        'losses_amount' => 5,
        'has_deficit' => true,
    ]);

    Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => ExpenceType::factory()->create()->id, 'amount' => 30]);
    Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 50, 'method' => RevenueMethod::CASH]);

    return $shift;
}

function managerWith(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $role = Role::findOrCreate('shift-money-test', 'web');
    $role->syncPermissions(['access_admin_panel', ...$permissions]);
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

describe('ShiftMoneyService', function () {
    it('adds an expense to the open shift with an audit row and no recompute', function () {
        $shift = Shift::factory()->active()->create(['start_cash' => 100, 'business_date' => now()->toDateString()]);

        $expense = $this->service->addExpense($shift, ['expence_type_id' => $this->type->id, 'amount' => 40, 'notes' => 'كهرباء']);

        expect($expense->shift_id)->toBe($shift->id)
            ->and($shift->fresh()->money_edited_count)->toBe(0)
            ->and($shift->fresh()->end_cash)->toBeNull();

        $log = AuditLog::query()->where('auditable_type', $expense->getMorphClass())->sole();
        expect($log->action)->toBe(AuditAction::CREATED)
            ->and($log->context)->toBe('shift_money')
            ->and($log->shift_id)->toBe($shift->id)
            ->and((float) $log->new_values['amount'])->toBe(40.0);

        expect($this->service->summary($shift)['expected_cash'])->toBe(60.0);
    });

    it('recomputes expected cash of a closed shift and leaves the legacy count and shortage alone', function () {
        $shift = closedShiftWithMoney();

        $this->service->addExpense($shift, ['expence_type_id' => $this->type->id, 'amount' => 20], 'فاتورة نسيها الكاشير');

        $shift->refresh();
        expect((float) $shift->end_cash)->toBe(100.0)
            ->and((float) $shift->real_cash)->toBe(115.0)
            ->and((float) $shift->losses_amount)->toBe(5.0)
            ->and($shift->has_deficit)->toBeTrue()
            ->and($shift->money_edited_count)->toBe(1)
            ->and($shift->money_edited_at)->not->toBeNull();

        $shiftLog = AuditLog::query()->where('auditable_type', $shift->getMorphClass())->sole();
        expect($shiftLog->reason)->toBe('فاتورة نسيها الكاشير')
            ->and((float) $shiftLog->old_values['end_cash'])->toBe(120.0)
            ->and((float) $shiftLog->new_values['end_cash'])->toBe(100.0)
            ->and($shiftLog->new_values)->not->toHaveKey('real_cash')
            ->and($shiftLog->new_values)->not->toHaveKey('losses_amount');
    });

    it('moves expected cash for cash revenues only', function () {
        $shift = closedShiftWithMoney();
        $revenue = $shift->revenues()->first();

        $this->service->updateRevenue($revenue, ['amount' => 80], 'تصحيح المبلغ');
        expect((float) $shift->fresh()->end_cash)->toBe(150.0);

        $this->service->updateRevenue($revenue->fresh(), ['method' => 'card'], 'كان فيزا');
        expect((float) $shift->fresh()->end_cash)->toBe(70.0);

        $this->service->deleteRevenue($revenue->fresh(), 'مكرر');
        expect(Revenue::query()->count())->toBe(0)
            ->and((float) $shift->fresh()->end_cash)->toBe(70.0)
            ->and($shift->fresh()->money_edited_count)->toBe(3);

        $deleted = AuditLog::query()->where('action', AuditAction::DELETED)->sole();
        expect($deleted->reason)->toBe('مكرر')
            ->and((float) $deleted->old_values['amount'])->toBe(80.0);
    });

    it('updates and deletes expenses of a closed shift', function () {
        $shift = closedShiftWithMoney();
        $expense = $shift->expenses()->first();

        $this->service->updateExpense($expense, ['amount' => 10], 'خطأ في المبلغ');
        expect((float) $shift->fresh()->end_cash)->toBe(140.0);

        $this->service->deleteExpense($expense->fresh(), 'مش مصروف');
        expect(Expense::query()->count())->toBe(0)
            ->and((float) $shift->fresh()->end_cash)->toBe(150.0);
    });

    it('requires a reason on a closed shift', function () {
        $shift = closedShiftWithMoney();

        expect(fn () => $this->service->addExpense($shift, ['expence_type_id' => $this->type->id, 'amount' => 5], '  '))
            ->toThrow(ValidationException::class);

        expect(Expense::query()->count())->toBe(1)
            ->and(AuditLog::query()->count())->toBe(0);
    });

    it('needs shift_money.edit_closed for a closed shift and shift_money.manage for any', function () {
        $closed = closedShiftWithMoney();
        $open = Shift::factory()->active()->create();

        actingAs(managerWith(['shift_money.manage']));
        expect($this->service->canEdit($open))->toBeTrue()
            ->and($this->service->canEdit($closed))->toBeFalse();
        $this->service->addExpense($open, ['expence_type_id' => $this->type->id, 'amount' => 5]);
        expect(fn () => $this->service->addExpense($closed, ['expence_type_id' => $this->type->id, 'amount' => 5], 'سبب'))
            ->toThrow(AuthorizationException::class);

        actingAs(User::factory()->cashier()->create());
        expect(fn () => $this->service->addExpense($open, ['expence_type_id' => $this->type->id, 'amount' => 5]))
            ->toThrow(AuthorizationException::class);
    });

    it('re-sends the changed row, the shift and that day summary', function () {
        $shift = closedShiftWithMoney();
        pairPlatform();

        $this->service->addExpense($shift, ['expence_type_id' => $this->type->id, 'amount' => 5], 'سبب');

        $types = SyncOutbox::query()->pluck('doc_type')->all();
        expect($types)->toContain(DocumentType::Expense->value)
            ->toContain(DocumentType::Shift->value)
            ->toContain(DocumentType::DaySummary->value)
            ->toContain(DocumentType::AuditLog->value);
    });

    it('keeps the close-time formula: recompute equals availableCash', function () {
        $shift = closedShiftWithMoney();
        $this->service->addRevenue($shift, ['revenue_type_id' => RevenueType::factory()->create()->id, 'amount' => 12.5], 'إيجار');

        $expected = round((float) app(ShiftsReportService::class)->calculateShiftStats($shift->fresh())['availableCash'], 2);
        expect((float) $shift->fresh()->end_cash)->toBe($expected)
            ->and(Revenue::query()->latest('id')->first()->user_id)->toBe($this->admin->id);
    });
});

describe('shift money page', function () {
    it('opens for shift_money.manage and not for the cashier', function () {
        expect(ShiftMoneyPage::canAccess())->toBeTrue();
        $this->get(ShiftMoneyPage::getUrl())->assertOk();

        actingAs(User::factory()->cashier()->create());
        expect(ShiftMoneyPage::canAccess())->toBeFalse();
    });

    it('defaults to the open shift and shows the revenues table only when the feature is on', function () {
        $open = Shift::factory()->active()->create();

        $page = livewire(ShiftMoneyPage::class)->assertSuccessful();
        expect((int) $page->get('filters')['shift_id'])->toBe($open->id);

        app(FeatureService::class)->disable(OptionalFeature::REVENUES);
        expect($page->instance()->getWidgets())->not->toContain(ShiftMoneyRevenuesTable::class);

        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        expect($page->instance()->getWidgets())->toContain(ShiftMoneyRevenuesTable::class);
    });

    it('shows no counted cash or shortage card for a closed shift', function () {
        $shift = closedShiftWithMoney();

        livewire(ShiftMoneySummaryStats::class, ['pageFilters' => ['shift_id' => $shift->id]])
            ->assertSee('النقدية المتوقعة')
            ->assertDontSee('النقدية الفعلية')
            ->assertDontSee('العجز')
            ->assertDontSee('الزيادة');
    });

    it('adds an expense to a closed shift from the table with a reason', function () {
        $shift = closedShiftWithMoney();

        livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $shift->id]])
            ->callAction(TestAction::make('addExpense')->table(), [
                'expence_type_id' => $this->type->id,
                'amount' => 20,
                'reason' => '',
            ])
            ->assertHasFormErrors(['reason' => 'required']);

        livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $shift->id]])
            ->callAction(TestAction::make('addExpense')->table(), [
                'expence_type_id' => $this->type->id,
                'amount' => 20,
                'reason' => 'فاتورة متأخرة',
            ])
            ->assertHasNoFormErrors()
            ->assertDispatched('shift-money-changed');

        expect((float) $shift->fresh()->end_cash)->toBe(100.0);
    });

    it('edits a revenue of the open shift without asking for a reason', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        $shift = Shift::factory()->active()->create();
        $revenue = Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 50]);

        livewire(ShiftMoneyRevenuesTable::class, ['pageFilters' => ['shift_id' => $shift->id]])
            ->callAction(TestAction::make('editRevenue')->table($revenue), [
                'amount' => 75,
                'method' => 'card',
            ])
            ->assertHasNoFormErrors();

        expect((float) $revenue->fresh()->amount)->toBe(75.0)
            ->and($revenue->fresh()->method)->toBe(RevenueMethod::CARD)
            ->and(AuditLog::query()->where('action', AuditAction::UPDATED)->count())->toBe(1);
    });

    it('hides the edit actions on a closed shift without shift_money.edit_closed', function () {
        $shift = closedShiftWithMoney();
        actingAs(managerWith(['shift_money.manage']));

        livewire(ShiftMoneyExpensesTable::class, ['pageFilters' => ['shift_id' => $shift->id]])
            ->assertActionHidden(TestAction::make('addExpense')->table())
            ->assertActionHidden(TestAction::make('deleteExpense')->table($shift->expenses()->first()));
    });
});

describe('expenses resource is read-only', function () {
    it('offers no create, edit or delete', function () {
        $expense = Expense::factory()->create(['expence_type_id' => $this->type->id]);

        expect(ExpenseResource::canCreate())->toBeFalse()
            ->and(ExpenseResource::canEdit($expense))->toBeFalse()
            ->and(ExpenseResource::canDelete($expense))->toBeFalse()
            ->and(array_keys(ExpenseResource::getPages()))->toBe(['index', 'view']);

        $this->get(ExpenseResource::getUrl('index'))->assertOk();
    });
});

describe('expenses and revenues report', function () {
    it('is the plain expenses report while revenues are off', function () {
        app(FeatureService::class)->disable(OptionalFeature::REVENUES);
        Shift::factory()->closed()->create(['start_at' => now()->subDay()]);

        $page = livewire(ExpensesReport::class);
        expect(ExpensesReport::getNavigationLabel())->toBe('تقرير المصروفات')
            ->and($page->instance()->getWidgets())->not->toContain(ExpensesRevenuesSummaryWidget::class);
    });

    it('adds the summary and revenue rows while revenues are on', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        Shift::factory()->closed()->create(['start_at' => now()->subDay()]);

        $page = livewire(ExpensesReport::class);
        expect(ExpensesReport::getNavigationLabel())->toBe('تقرير المصروفات والإيرادات')
            ->and($page->instance()->getWidgets())->toBe([
                ExpensesRevenuesSummaryWidget::class,
                PeriodShiftRevenuesDetailsTable::class,
                PeriodShiftExpensesTable::class,
                PeriodShiftExpensesDetailsTable::class,
            ]);
    });

    it('computes order revenue net of refunds plus revenue types minus expenses', function () {
        $shift = Shift::factory()->closed()->create(['start_at' => now()->subDay()]);
        $outside = Shift::factory()->closed()->create(['start_at' => now()->subDays(30)]);

        Order::factory()->create([
            'shift_id' => $shift->id, 'status' => OrderStatus::COMPLETED,
            'sub_total' => 200, 'service' => 20, 'tax' => 0, 'discount' => 10, 'fees_total' => 15, 'equipment_total' => 25, 'total' => 250,
        ]);
        Order::factory()->create(['shift_id' => $shift->id, 'status' => OrderStatus::CANCELLED, 'total' => 999]);
        Order::factory()->create(['shift_id' => $outside->id, 'status' => OrderStatus::COMPLETED, 'total' => 999]);
        $order = Order::query()->where('total', 250)->sole();
        OrderReturn::create(['order_id' => $order->id, 'user_id' => $this->admin->id, 'shift_id' => $shift->id, 'total_refund' => 40, 'reverse_stock' => false]);

        $rent = RevenueType::factory()->create(['name' => 'إيجار']);
        Revenue::factory()->create(['shift_id' => $shift->id, 'revenue_type_id' => $rent->id, 'amount' => 100]);
        Revenue::factory()->card()->create(['shift_id' => $shift->id, 'revenue_type_id' => $rent->id, 'amount' => 30]);
        Revenue::factory()->create(['shift_id' => $outside->id, 'revenue_type_id' => $rent->id, 'amount' => 999]);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => $this->type->id, 'amount' => 45]);
        $salary = ExpenceType::query()->create(['name' => 'رواتب']);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => $salary->id, 'amount' => 10]);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => $salary->id, 'amount' => 5]);
        Expense::factory()->create(['shift_id' => $outside->id, 'expence_type_id' => $salary->id, 'amount' => 999]);

        $scope = app(ShiftsReportService::class)->shiftIdsQuery(now()->subDays(6)->toDateString(), now()->toDateString());
        $summary = app(ExpensesRevenuesReportService::class)->summary($scope);

        expect($summary['orders']['gross'])->toBe(250.0)
            ->and($summary['orders']['refunds'])->toBe(40.0)
            ->and($summary['orders']['net'])->toBe(210.0)
            ->and($summary['orders']['parts'])->toMatchArray(['الأصناف' => 200.0, 'الخدمة' => 20.0, 'الخصم' => -10.0, 'رسوم إضافية' => 15.0, 'المعدات' => 25.0])
            ->and($summary['revenue_types'])->toBe([['name' => 'إيجار', 'count' => 2, 'cash' => 100.0, 'card' => 30.0, 'total' => 130.0]])
            ->and($summary['total_revenues'])->toBe(340.0)
            ->and($summary['expense_types'])->toBe([
                ['name' => $this->type->name, 'count' => 1, 'cash' => 45.0, 'card' => 0.0, 'total' => 45.0],
                ['name' => 'رواتب', 'count' => 2, 'cash' => 15.0, 'card' => 0.0, 'total' => 15.0],
            ])
            ->and($summary['expenses'])->toBe(60.0)
            ->and($summary['net'])->toBe(280.0);
    });

    it('renders the summary widget', function () {
        app(FeatureService::class)->enable(OptionalFeature::REVENUES);
        $shift = Shift::factory()->closed()->create(['start_at' => now()->subDay()]);
        Revenue::factory()->create(['shift_id' => $shift->id, 'amount' => 100]);
        $fuel = ExpenceType::query()->create(['name' => 'وقود']);
        Expense::factory()->create(['shift_id' => $shift->id, 'expence_type_id' => $fuel->id, 'amount' => 37]);

        livewire(ExpensesRevenuesSummaryWidget::class, ['pageFilters' => ['filterType' => 'shifts', 'shifts' => [$shift->id]]])
            ->assertSuccessful()
            ->assertSee('إيرادات الطلبات')
            ->assertSee('وقود')
            ->assertSee('37.00')
            ->assertSee('100.00');
    });
});
