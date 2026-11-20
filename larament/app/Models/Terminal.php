<?php

namespace App\Models;

use App\Models\Concerns\PrintsViaAgent;
use App\PrintAgent\PrintAgentTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One cash drawer / cashier counter («درج»).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $warehouse_id
 * @property string|null $receipt_printer
 * @property string $print_via server | agent
 * @property string|null $agent_printer
 * @property string|null $agent_token_hash
 * @property Carbon|null $agent_paired_at
 * @property Carbon|null $agent_last_seen_at
 * @property array<string, mixed>|null $agent_info
 * @property int $sort
 */
class Terminal extends Model implements PrintAgentTarget
{
    use PrintsViaAgent;

    public const DEFAULT_NAME = 'الكاشير';

    protected $fillable = [
        'name',
        'is_active',
        'is_default',
        'warehouse_id',
        'receipt_printer',
        'print_via',
        'agent_printer',
        'sort',
    ];

    /** @var list<string> */
    protected $hidden = ['agent_token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'print_via' => self::PRINT_VIA_SERVER,
        'sort' => 0,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort' => 'integer',
        'warehouse_id' => 'integer',
        'agent_paired_at' => 'datetime',
        'agent_last_seen_at' => 'datetime',
        'agent_info' => 'array',
    ];

    /**
     * The hidden drawer every shift uses while multiple terminals are off.
     */
    public static function default(): self
    {
        return self::query()->where('is_default', true)->orderBy('id')->first()
            ?? self::query()->create([
                'name' => self::DEFAULT_NAME,
                'is_active' => true,
                'is_default' => true,
                'sort' => 0,
            ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<TerminalSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TerminalSession::class);
    }

    /** @return HasMany<TerminalDevice, $this> */
    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The warehouse this sales point issues stock from while sales points
     * are separated: its own warehouse when active, else the default one.
     */
    public function issueWarehouseId(): int
    {
        if ($this->warehouse_id !== null) {
            $this->loadMissing('warehouse');
            if ($this->warehouse instanceof Warehouse && $this->warehouse->is_active) {
                return (int) $this->warehouse_id;
            }
        }

        return Warehouse::default()->id;
    }

    public function devices(): HasMany
    {
        return $this->hasMany(TerminalDevice::class);
    }

    /**
     * The receipt printer of this drawer, else the Settings cashier printer.
     */
    public function receiptPrinter(): ?string
    {
        $printer = trim((string) $this->receipt_printer);

        return $printer === '' ? null : $printer;
    }

    public function agentTargetColumn(): string
    {
        return 'terminal_id';
    }

    public function agentTargetKey(): string
    {
        return 'terminal:'.$this->id;
    }

    public function agentEnabled(): bool
    {
        return $this->printsViaAgent() && $this->is_active;
    }
}
