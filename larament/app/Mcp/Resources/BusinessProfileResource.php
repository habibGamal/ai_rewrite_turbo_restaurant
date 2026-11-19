<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Tools\GetBusinessContextTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/** The get_business_context answer, for clients that read resources. */
#[Name('business_profile')]
#[Title('Business profile')]
#[Description('The shop now: name, business type, today\'s date, open shift, enabled features, sales channels and sales points. Same as the get_business_context tool.')]
#[Uri('pos://business-profile')]
#[MimeType('application/json')]
class BusinessProfileResource extends Resource
{
    private readonly GetBusinessContextTool $context;

    public function __construct(?GetBusinessContextTool $context = null)
    {
        $this->context = $context ?? app(GetBusinessContextTool::class);
    }

    public function shouldRegister(): bool
    {
        return $this->context->allowed();
    }

    public function handle(): Response
    {
        return $this->context->handle(new Request);
    }
}
