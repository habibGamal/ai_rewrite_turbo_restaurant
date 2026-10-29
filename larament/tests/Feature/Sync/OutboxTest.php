<?php

use App\Models\SyncOutbox;
use App\Sync\DocumentType;
use App\Sync\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('records nothing when the POS is not paired', function () {
    Outbox::record(DocumentType::Order, (string) Str::uuid());
    expect(SyncOutbox::count())->toBe(0);
});

it('records a row when paired', function () {
    pairPlatform();
    Outbox::record(DocumentType::Order, $u = (string) Str::uuid());
    expect(SyncOutbox::first())
        ->doc_type->toBe('order')
        ->doc_uuid->toBe($u)
        ->op->toBe('upsert')
        ->priority->toBe(5);
});

it('rolls the outbox row back with the business transaction', function () {
    pairPlatform();
    try {
        DB::transaction(function () {
            Outbox::record(DocumentType::Order, (string) Str::uuid());
            throw new RuntimeException;
        });
    } catch (RuntimeException) {
    }
    expect(SyncOutbox::count())->toBe(0);
});

it('stores the token encrypted at rest', function () {
    pairPlatform(token: 'abc');
    expect(DB::table('platform_connections')->value('token'))->not->toBe('abc');
});
