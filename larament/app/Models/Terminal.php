<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One cash drawer / cashier counter («درج»).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_default
 * @property string|null $receipt_printer
 * @property string $print_via server | agent
 * @property string|null $agent_printer
 * @property string|null $agent_token_hash
 * @property Carbon|null $agent_paired_at
 * @property Carbon|null $agent_last_seen_at
 * @property array<string, mixed>|null $agent_info
 * @property int $sort
 */
class Terminal extends Model
{
    public const DEFAULT_NAME = 'الكاشير';

    public const PRINT_VIA_SERVER = 'server';

    public const PRINT_VIA_AGENT = 'agent';

    protected $fillable = [
        'name',
        'is_active',
        'is_default',
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

    /**
     * Receipts and the cash drawer go through the Turbo Label app on this
     * drawer's computer (POS hosted away from the shop).
     */
    public function printsViaAgent(): bool
    {
        return $this->print_via === self::PRINT_VIA_AGENT;
    }

    public function agentPaired(): bool
    {
        return $this->agent_token_hash !== null;
    }

    public function agentPrinter(): ?string
    {
        $printer = trim((string) $this->agent_printer);

        return $printer === '' ? null : $printer;
    }

    /**
     * Windows printers the paired app reported.
     *
     * @return list<string>
     */
    public function agentPrinters(): array
    {
        $printers = $this->agent_info['printers'] ?? [];

        return is_array($printers) ? array_values(array_filter($printers, 'is_string')) : [];
    }
}
