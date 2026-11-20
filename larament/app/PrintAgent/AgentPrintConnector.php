<?php

namespace App\PrintAgent;

use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Collects the ESC/POS bytes a Printer writes instead of sending them to a
 * socket. Closing the printer only ends the buffer; the bytes are queued for
 * the target's Turbo Label app by send(), which callers run only after the
 * whole ticket/receipt was built, so a failure half way never prints half a job.
 */
class AgentPrintConnector implements PrintConnector
{
    private string $buffer = '';

    public function __construct(
        public readonly PrintAgentTarget $target,
        public readonly string $kind,
    ) {}

    public function write($data)
    {
        $this->buffer .= $data;
    }

    public function read($len)
    {
        // Nothing comes back from a queued job.
        return '';
    }

    public function finalize()
    {
        // Nothing to close: see send().
    }

    public function __destruct()
    {
        // Nothing to release.
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    /**
     * Queue the bytes and wait briefly for the app's answer.
     *
     * @throws PrintAgentException
     */
    public function send(): PrintAgentJob
    {
        $queue = app(PrintAgentQueue::class);
        $job = $queue->push($this->target, $this->kind, $this->buffer);
        $queue->await($job);

        return $job;
    }
}
