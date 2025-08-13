<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterWithManagementCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:register-with-management';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register this application instance with the management operations system';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔗 Registering with management operations system...');

        try {
            $managementUrl = config('app.manage_operations_url');
            if (!$managementUrl) {
                $this->error('❌ MANAGE_OPERATIONS_URL is not configured');
                return Command::FAILURE;
            }

            $registrationData = [
                'app_id' => config('app.id'),
                'app_name' => config('app.name'),
                'external_url' => config('app.external_url'),
                'management_secret_key' => config('app.management_secret_key'),
                'server_info' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'environment' => config('app.env'),
                    'timezone' => config('app.timezone'),
                    'registered_at' => now()->toISOString(),
                ],
            ];

            $response = Http::timeout(30)
                ->post(rtrim($managementUrl, '/') . '/api/app-instances/register', $registrationData);

            if ($response->successful()) {
                $this->info('✅ Successfully registered with management operations system');
                $this->line('Instance ID: ' . config('app.id'));
                $this->line('Management URL: ' . $managementUrl);
                Log::info('Successfully registered with management operations system', [
                    'app_id' => config('app.id'),
                    'management_url' => $managementUrl,
                ]);
                return Command::SUCCESS;
            } else {
                $this->error('❌ Failed to register with management operations system');
                $this->line('Response: ' . $response->body());
                Log::error('Failed to register with management operations system', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error('❌ Registration failed: ' . $e->getMessage());
            Log::error('Registration with management operations failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}
