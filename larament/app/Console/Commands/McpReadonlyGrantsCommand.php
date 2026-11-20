<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mcp\Support\AiViews;
use Illuminate\Console\Command;

/**
 * Prints the MySQL statements that create a database user allowed to read
 * the ai_* views and nothing else, for DB_AI_USERNAME / DB_AI_PASSWORD.
 * It only prints; a database admin runs them.
 */
class McpReadonlyGrantsCommand extends Command
{
    protected $signature = 'mcp:readonly-grants {username=turbo_ai : The read-only database user} {--host=localhost : Host part of the MySQL account}';

    protected $description = 'Print SQL that creates a SELECT-only database user for the AI assistant\'s direct queries';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $account = sprintf("'%s'@'%s'", $this->argument('username'), $this->option('host'));

        $this->line("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY '<strong password>';");
        foreach (AiViews::names() as $view) {
            $this->line("GRANT SELECT ON `{$database}`.`{$view}` TO {$account};");
        }
        $this->line('FLUSH PRIVILEGES;');
        $this->newLine();
        $this->comment('Then set DB_AI_USERNAME and DB_AI_PASSWORD in .env and run php artisan config:cache.');

        return self::SUCCESS;
    }
}
