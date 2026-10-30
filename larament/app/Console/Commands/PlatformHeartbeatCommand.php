<?php

namespace App\Console\Commands;

use App\Sync\HeartbeatService;
use Illuminate\Console\Command;
use Throwable;

class PlatformHeartbeatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'platform:heartbeat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send health heartbeat to central platform';

    /**
     * Execute the console command.
     */
    public function handle(HeartbeatService $service): int
    {
        try {
            $service->beat();
            $this->info('Heartbeat sent successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Heartbeat failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
