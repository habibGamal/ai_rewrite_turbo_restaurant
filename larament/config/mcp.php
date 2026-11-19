<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Redirect URI prefixes OAuth clients may register (the package appends
    | "/" to each). Never "*": anyone could register a look-alike client and
    | phish a consent. One disallowed URI rejects the whole registration, and
    | Gemini sends six at once, so keep all the Google hosts. localhost and
    | 127.0.0.1 allow any port, for CLIs such as Claude Code and Gemini CLI.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,https://chatgpt.com,https://chat.openai.com,'
        .'https://oauth-redirect.googleusercontent.com,https://oauth-redirect-sandbox.googleusercontent.com,'
        .'https://oauth-redirect-test.googleusercontent.com,https://vertexaisearch.cloud.google.com,'
        .'http://localhost,http://127.0.0.1',
    ))))),

    'custom_schemes' => [],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | OAuth issuer identifier (RFC 8414). null = url('/').
    |
    */

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],

];
