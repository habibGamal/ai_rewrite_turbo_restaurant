<?php

namespace App\Models\Concerns;

use App\PrintAgent\PrintAgentTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Shared Turbo Label print-agent helpers for models that can queue raw
 * ESC/POS bytes to a paired desktop app (sales points and kitchen printers).
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements PrintAgentTarget
 */
trait PrintsViaAgent
{
    public function agentTargetId(): int
    {
        return (int) $this->getKey();
    }

    public function agentTargetName(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function printsViaAgent(): bool
    {
        return $this->getAttribute('print_via') === PrintAgentTarget::PRINT_VIA_AGENT;
    }

    public function agentPaired(): bool
    {
        return $this->getAttribute('agent_token_hash') !== null;
    }

    public function agentPrinter(): ?string
    {
        $printer = trim((string) $this->getAttribute('agent_printer'));

        return $printer === '' ? null : $printer;
    }

    /**
     * Windows printers the paired app reported.
     *
     * @return list<string>
     */
    public function agentPrinters(): array
    {
        $info = $this->getAttribute('agent_info');
        $printers = is_array($info) ? ($info['printers'] ?? []) : [];

        return is_array($printers) ? array_values(array_filter($printers, 'is_string')) : [];
    }

    public function agentLastSeenAt(): ?Carbon
    {
        $lastSeen = $this->getAttribute('agent_last_seen_at');

        return $lastSeen instanceof Carbon ? $lastSeen : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateAgentState(array $attributes): void
    {
        $this->forceFill($attributes)->save();
    }
}
