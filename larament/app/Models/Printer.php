<?php

namespace App\Models;

use App\Models\Concerns\PrintsViaAgent;
use App\PrintAgent\PrintAgentTarget;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Kitchen printer («طابعة المطبخ»).
 *
 * @property int $id
 * @property string $name
 * @property string|null $ip_address
 * @property string $print_via server | agent
 * @property string|null $agent_printer
 * @property string|null $agent_token_hash
 * @property Carbon|null $agent_paired_at
 * @property Carbon|null $agent_last_seen_at
 * @property array<string, mixed>|null $agent_info
 */
class Printer extends Model implements PrintAgentTarget
{
    use HasFactory, PrintsViaAgent;

    protected $fillable = [
        'name',
        'ip_address',
        'print_via',
        'agent_printer',
    ];

    /** @var list<string> */
    protected $hidden = ['agent_token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'print_via' => self::PRINT_VIA_SERVER,
    ];

    protected $casts = [
        'agent_paired_at' => 'datetime',
        'agent_last_seen_at' => 'datetime',
        'agent_info' => 'array',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function ipAddress(): ?string
    {
        $ip = trim((string) $this->ip_address);

        return $ip === '' ? null : $ip;
    }

    public function agentTargetColumn(): string
    {
        return 'printer_id';
    }

    public function agentTargetKey(): string
    {
        return 'printer:'.$this->id;
    }

    public function agentEnabled(): bool
    {
        return $this->printsViaAgent();
    }
}
