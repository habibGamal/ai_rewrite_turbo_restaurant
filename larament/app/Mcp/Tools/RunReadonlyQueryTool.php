<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\AiQueryAccess;
use App\Mcp\Support\ReadOnlyQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('run_readonly_query')]
#[Title('Direct read-only query')]
#[Description(<<<'TEXT'
Runs one SELECT on the ai_* views (call describe_data_model first) and returns up to 500 rows. Last resort for questions no other tool answers, such as unusual combinations or a custom list.
Never use it for headline sales, profit, product rankings, shifts, stock or customers: the dedicated tools apply the shop's rules for those. Read only; 5-second limit.
TEXT)]
#[IsReadOnly]
class RunReadonlyQueryTool extends BusinessTool
{
    private readonly ReadOnlyQuery $query;

    /** Services come from the container when the tool is built without them. */
    public function __construct(?ReadOnlyQuery $query = null)
    {
        $this->query = $query ?? app(ReadOnlyQuery::class);
    }

    protected function reportPages(): array
    {
        return [];
    }

    public function allowed(): bool
    {
        return AiQueryAccess::allowed();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()->required()->description('One SELECT on ai_* views, e.g. SELECT channel, COUNT(*) AS orders FROM ai_orders WHERE status = \'completed\' AND business_date BETWEEN \'2026-11-01\' AND \'2026-11-30\' GROUP BY channel'),
            'purpose' => $schema->string()->required()->description('The user\'s question this query answers, in one line. Kept in the audit log.'),
        ];
    }

    protected function run(Request $request): array
    {
        $result = $this->query->run((string) $request->get('sql', ''));

        $caveats = ['Direct query: the numbers follow the SQL as written, not the app reports\' rules. Say so when you present them.'];
        if ($result['truncated']) {
            $caveats[] = 'Only the first '.ReadOnlyQuery::MAX_ROWS.' rows were returned. Aggregate in SQL for complete totals.';
        }

        return $this->envelope(null, $result, [], $caveats);
    }
}
