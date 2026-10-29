<?php

use App\Models\User;
use App\Sync\Builders\StaffBuilder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

it('builds a staff snapshot with role', function () {
    pairPlatform();

    $role = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);

    $user = User::factory()->create([
        'name' => 'Cashier Ahmed',
        'email' => 'ahmed@example.com',
        'password' => 'secret123',
    ]);
    $user->syncRoles([$role]);

    $data = app(StaffBuilder::class)->build($user->uuid);

    expect($data)->toEqual([
        'name' => 'Cashier Ahmed',
        'role' => 'cashier',
    ])
        ->and($data)->not->toHaveKeys(['email', 'password', 'remember_token']);
});

it('builds a staff snapshot without role', function () {
    pairPlatform();

    $user = User::factory()->create([
        'name' => 'Waiter Mohamed',
    ]);
    $user->syncRoles([]);

    $data = app(StaffBuilder::class)->build($user->uuid);

    expect($data)->toEqual([
        'name' => 'Waiter Mohamed',
        'role' => null,
    ]);
});

it('returns null for an unknown staff uuid', function () {
    expect(app(StaffBuilder::class)->build((string) Str::uuid()))->toBeNull();
});
