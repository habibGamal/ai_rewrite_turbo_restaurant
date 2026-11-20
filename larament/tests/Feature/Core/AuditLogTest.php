<?php

use App\Core\Audit\AuditLogger;
use App\Enums\AuditAction;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\ExpenceType;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use App\Sync\Builders\AuditLogBuilder;
use App\Sync\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->shift = Shift::factory()->create(['user_id' => $this->user->id, 'business_date' => '2026-11-10']);
    $this->expense = Expense::factory()->create([
        'shift_id' => $this->shift->id,
        'expence_type_id' => ExpenceType::factory()->create()->id,
        'amount' => 50,
    ]);
});

it('records an update with only the changed values, the shift, business date and user', function () {
    $original = $this->expense->getOriginal();
    $this->expense->update(['amount' => 80]);

    $log = AuditLogger::updated($this->expense, $original, reason: '  خطأ في المبلغ ', context: 'closed_shift_edit');

    expect($log->fresh())
        ->action->toBe(AuditAction::UPDATED)
        ->auditable_type->toBe(Expense::class)
        ->auditable_id->toBe($this->expense->id)
        ->shift_id->toBe($this->shift->id)
        ->user_id->toBe($this->user->id)
        ->reason->toBe('خطأ في المبلغ')
        ->context->toBe('closed_shift_edit')
        ->and($log->fresh()->business_date->format('Y-m-d'))->toBe('2026-11-10')
        ->and((float) $log->old_values['amount'])->toBe(50.0)
        ->and((float) $log->new_values['amount'])->toBe(80.0)
        ->and($log->new_values)->not->toHaveKey('updated_at')
        ->and($log->uuid)->not->toBeEmpty();
});

it('records created and deleted rows with full values', function () {
    $created = AuditLogger::created($this->expense);
    $deleted = AuditLogger::deleted($this->expense, reason: 'مكرر');

    expect($created->old_values)->toBeNull()
        ->and($created->new_values)->toHaveKey('uuid', $this->expense->uuid)
        ->and($deleted->action)->toBe(AuditAction::DELETED)
        ->and($deleted->old_values)->toHaveKey('uuid', $this->expense->uuid)
        ->and($deleted->new_values)->toBeNull();
});

it('takes the shift from a shift model and allows rows without a shift', function () {
    $shiftLog = AuditLogger::record($this->shift, AuditAction::UPDATED, ['end_cash' => 10], ['end_cash' => 20]);
    $userLog = AuditLogger::record($this->user, AuditAction::UPDATED, ['password' => 'x', 'name' => 'a'], ['name' => 'b']);

    expect($shiftLog->shift_id)->toBe($this->shift->id)
        ->and($userLog->shift_id)->toBeNull()
        ->and($userLog->business_date)->toBeNull()
        ->and($userLog->old_values)->toBe(['name' => 'a']);
});

it('is append-only', function () {
    $log = AuditLogger::created($this->expense);

    expect(fn () => $log->update(['reason' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(AuditLog::count())->toBe(1);
});

it('leaves no row when the audited transaction rolls back', function () {
    try {
        DB::transaction(function () {
            AuditLogger::created($this->expense);
            throw new RuntimeException('fail');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::count())->toBe(0);
});

it('queues an audit row for sync and builds its snapshot', function () {
    pairPlatform();

    $original = $this->expense->getOriginal();
    $this->expense->update(['amount' => 70]);
    $log = AuditLogger::updated($this->expense, $original, reason: 'تصحيح', context: 'closed_shift_edit');

    expect(DB::table('sync_outbox')->where('doc_type', DocumentType::AuditLog->value)->where('doc_uuid', $log->uuid)->exists())->toBeTrue();

    $data = app(AuditLogBuilder::class)->build($log->uuid);

    expect($data)->toMatchArray([
        'shift_uuid' => $this->shift->uuid,
        'business_date' => '2026-11-10',
        'user_uuid' => $this->user->uuid,
        'auditable_type' => 'expense',
        'auditable_uuid' => $this->expense->uuid,
        'action' => 'updated',
        'reason' => 'تصحيح',
        'context' => 'closed_shift_edit',
    ])->and((float) $data['new_values']['amount'])->toBe(70.0);
});

it('keeps the uuid of a deleted audited row in the snapshot', function () {
    $uuid = $this->expense->uuid;
    $this->expense->delete();
    $log = AuditLogger::deleted($this->expense);

    expect(app(AuditLogBuilder::class)->build($log->uuid)['auditable_uuid'])->toBe($uuid);
});

it('shows the audit log to users with view_audit_logs and never offers writes', function () {
    AuditLogger::created($this->expense, reason: 'سبب');
    $log = AuditLog::firstOrFail();

    Livewire::test(ListAuditLogs::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$log]);
    Livewire::test(ViewAuditLog::class, ['record' => $log->getKey()])->assertOk();

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($log))->toBeFalse()
        ->and(AuditLogResource::canDelete($log))->toBeFalse()
        ->and(AuditLogResource::hasPage('edit'))->toBeFalse();
});

it('hides the audit log from users without the permission', function () {
    Permission::findOrCreate('view_audit_logs', 'web');
    $manager = User::factory()->viewer()->create();
    $this->actingAs($manager);

    expect(AuditLogResource::canViewAny())->toBeFalse();

    $manager->givePermissionTo('view_audit_logs');
    expect(AuditLogResource::canViewAny())->toBeTrue();
});
