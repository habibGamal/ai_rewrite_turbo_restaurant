<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multiple cash drawers («أدراج») in one store shift.
 *
 * A terminal is one cash drawer / cashier counter. A terminal session is one
 * drawer being open inside one shift. Every money row taken at the POS points
 * at the session of the drawer that took it, and every order points at the
 * terminal it was opened from.
 *
 * While the MULTI_TERMINAL feature is off, each shift gets one session on a
 * hidden default terminal, so turning the feature on later needs no data
 * migration. Existing shifts are backfilled the same way here.
 */
return new class extends Migration
{
    /**
     * Tables whose rows are money taken or paid out by a drawer.
     *
     * @var list<string>
     */
    private const MONEY_TABLES = [
        'payments',
        'expenses',
        'revenues',
        'order_returns',
        'external_order_collections',
        'external_seller_settlements',
        'held_sales',
    ];

    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            // The hidden drawer every shift uses while the feature is off.
            $table->boolean('is_default')->default(false);
            // Receipt printer + cash drawer kick; empty = the Settings printer.
            $table->string('receipt_printer')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained();
            // The cashier signed in on the drawer now; changes on a handover.
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('opened_by')->nullable()->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->decimal('opening_cash', 10, 2)->default(0);
            // What should be in the drawer when it closed (no counting).
            $table->decimal('expected_cash', 10, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'closed_at']);
            $table->index(['terminal_id', 'closed_at']);
        });

        foreach (self::MONEY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('terminal_session_id')->nullable()->constrained('terminal_sessions')->nullOnDelete();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('terminal_id')->nullable()->constrained('terminals')->nullOnDelete();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terminal_id');
        });

        foreach (self::MONEY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('terminal_session_id');
            });
        }

        Schema::dropIfExists('terminal_sessions');
        Schema::dropIfExists('terminals');
    }

    /**
     * One hidden default drawer, one session per existing shift, and every
     * existing money row and order pointed at it.
     */
    private function backfill(): void
    {
        $now = now();

        $terminalId = DB::table('terminals')->where('is_default', true)->orderBy('id')->value('id') ?? DB::table('terminals')->insertGetId([
            'name' => 'الكاشير',
            'is_active' => true,
            'is_default' => true,
            'sort' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('shifts')->orderBy('id')->chunkById(500, function ($shifts) use ($terminalId, $now) {
            $rows = [];

            foreach ($shifts as $shift) {
                $closed = (bool) $shift->closed || $shift->end_at !== null;

                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'shift_id' => $shift->id,
                    'terminal_id' => $terminalId,
                    'user_id' => $shift->user_id,
                    'opened_by' => $shift->user_id,
                    'closed_by' => $closed ? $shift->user_id : null,
                    'opening_cash' => $shift->start_cash ?? 0,
                    'expected_cash' => $closed ? $shift->end_cash : null,
                    'opened_at' => $shift->start_at ?? $shift->created_at ?? $now,
                    'closed_at' => $closed ? ($shift->end_at ?? $shift->updated_at ?? $now) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('terminal_sessions')->insert($rows);
        });

        foreach (self::MONEY_TABLES as $name) {
            DB::table($name)
                ->whereNull('terminal_session_id')
                ->update(['terminal_session_id' => DB::raw(
                    "(SELECT MIN(terminal_sessions.id) FROM terminal_sessions WHERE terminal_sessions.shift_id = {$name}.shift_id)"
                )]);
        }

        DB::table('orders')->whereNull('terminal_id')->update(['terminal_id' => $terminalId]);
    }
};
