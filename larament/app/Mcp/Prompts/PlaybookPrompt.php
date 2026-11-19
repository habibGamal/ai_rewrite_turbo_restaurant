<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Tools\BusinessTool;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use RuntimeException;

/**
 * An analysis playbook (resources/ai/playbooks/<name>.md) served as an MCP
 * prompt: the tool order, the questions to answer and the answer shape.
 *
 * The front matter names the tools the playbook cannot work without; the
 * prompt is listed only when the user may call every one of them, so a
 * cashier never sees cash_reconciliation.
 */
final class PlaybookPrompt extends Prompt
{
    public const DIRECTORY = 'ai/playbooks';

    /**
     * @param  list<class-string<BusinessTool>>  $requires
     */
    private function __construct(
        private readonly string $promptName,
        private readonly string $promptTitle,
        private readonly string $promptDescription,
        private readonly string $body,
        private readonly array $requires,
    ) {}

    /**
     * Every playbook on disk.
     *
     * @param  array<int, class-string>  $tools  the server's tool classes, to resolve tool names
     * @return list<self>
     */
    public static function all(array $tools): array
    {
        $byName = [];
        foreach ($tools as $class) {
            if (is_subclass_of($class, BusinessTool::class)) {
                $byName[Container::getInstance()->make($class)->name()] = $class;
            }
        }

        $files = glob(resource_path(self::DIRECTORY.'/*.md')) ?: [];
        sort($files);

        return array_map(fn (string $path) => self::fromFile($path, $byName), $files);
    }

    /**
     * @param  array<string, class-string<BusinessTool>>  $toolsByName
     */
    public static function fromFile(string $path, array $toolsByName): self
    {
        $raw = (string) file_get_contents($path);
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $parts) !== 1) {
            throw new RuntimeException("Playbook {$path} has no front matter.");
        }

        $meta = [];
        foreach (preg_split('/\R/', $parts[1]) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $meta[trim($key)] = trim($value);
            }
        }

        $requires = [];
        foreach (array_filter(array_map('trim', explode(',', $meta['requires'] ?? ''))) as $tool) {
            $requires[] = $toolsByName[$tool] ?? throw new RuntimeException("Playbook {$path} requires unknown tool {$tool}.");
        }

        return new self(
            $meta['name'] ?? basename($path, '.md'),
            $meta['title'] ?? ucfirst(str_replace('_', ' ', basename($path, '.md'))),
            $meta['description'] ?? '',
            trim($parts[2]),
            $requires,
        );
    }

    public function name(): string
    {
        return $this->promptName;
    }

    public function title(): string
    {
        return $this->promptTitle;
    }

    public function description(): string
    {
        return $this->promptDescription;
    }

    /** @return list<class-string<BusinessTool>> */
    public function requiredTools(): array
    {
        return $this->requires;
    }

    public function shouldRegister(): bool
    {
        foreach ($this->requires as $class) {
            if (! Container::getInstance()->make($class)->allowed()) {
                return false;
            }
        }

        return true;
    }

    public function arguments(): array
    {
        return [
            new Argument('period', 'Optional period, e.g. "yesterday", "last month", "October 2026". The playbook default applies when empty.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $period = trim((string) $request->get('period', ''));

        return Response::text($period === ''
            ? $this->body
            : $this->body."\n\nPeriod the user asked for: {$period}. Use it instead of the playbook's default period.");
    }
}
