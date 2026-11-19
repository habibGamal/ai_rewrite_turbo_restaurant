<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Tools\BusinessTool;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('glossary')]
#[Title('Glossary')]
#[Description('Every metric the tools return (net sales, margin, cash difference, loss rate...), in English and Arabic.')]
#[Uri('pos://glossary')]
#[MimeType('text/markdown')]
class GlossaryResource extends Resource
{
    public const FILE = 'ai/glossary.md';

    /** Offered to anyone who can use at least one report-gated tool. */
    public function shouldRegister(): bool
    {
        if (Auth::user() === null) {
            return false;
        }

        foreach (BusinessTool::ANY_REPORT as $page) {
            if ($page::canAccess()) {
                return true;
            }
        }

        return false;
    }

    public function handle(): Response
    {
        return Response::text((string) file_get_contents(resource_path(self::FILE)));
    }
}
