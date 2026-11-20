<?php

namespace App\PrintAgent;

use Illuminate\Support\Carbon;

interface PrintAgentTarget
{
    public const PRINT_VIA_SERVER = 'server';

    public const PRINT_VIA_AGENT = 'agent';

    public function agentTargetColumn(): string;

    public function agentTargetId(): int;

    public function agentTargetKey(): string;

    public function agentTargetName(): string;

    public function printsViaAgent(): bool;

    public function agentEnabled(): bool;

    public function agentPaired(): bool;

    public function agentPrinter(): ?string;

    /** @return list<string> */
    public function agentPrinters(): array;

    public function agentLastSeenAt(): ?Carbon;

    /** @param array<string, mixed> $attributes */
    public function updateAgentState(array $attributes): void;
}
