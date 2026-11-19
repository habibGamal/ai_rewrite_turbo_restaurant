#!/usr/bin/env bash
# Check a remote laravel/mcp + Passport server the way Claude, ChatGPT and Gemini will see it.
# Usage: check-remote-mcp.sh <base-url> [mcp-path]     e.g. check-remote-mcp.sh https://app.example.com /mcp/restaurants
# Run from the Laravel project root to also revoke the test clients it registers (via artisan tinker).
set -u
BASE="${1:?base url, e.g. https://app.example.com}"; BASE="${BASE%/}"
MCP_PATH="${2:-/mcp}"; MCP="$BASE$MCP_PATH"
pass=0; fail=0
ok()  { echo "  PASS  $1"; pass=$((pass+1)); }
bad() { echo "  FAIL  $1"; fail=$((fail+1)); }
https_expected=0; [[ "$BASE" == https://* ]] && https_expected=1

echo "== 1. Unauthenticated MCP call -> 401 + WWW-Authenticate (for any Accept header)"
for accept in '*/*' 'application/json, text/event-stream'; do
  headers=$(curl -s -o /dev/null -D - -X POST "$MCP" -A "Python/3 aiohttp/3" -H "Content-Type: application/json" -H "Accept: $accept" \
    -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"check","version":"1"}}}')
  status=$(echo "$headers" | head -1 | awk '{print $2}')
  if [[ "$status" == "401" ]] && echo "$headers" | grep -qi '^www-authenticate:.*resource_metadata='; then ok "Accept: $accept -> 401 with resource_metadata"
  else bad "Accept: $accept -> HTTP $status $(echo "$headers" | grep -i '^location:' | tr -d '\r')  (302 to /login = add 'mcp/*' to shouldRenderJsonWhen)"; fi
done

echo "== 2. Discovery metadata"
prm=$(curl -s "$BASE/.well-known/oauth-protected-resource$MCP_PATH")
asm=$(curl -s "$BASE/.well-known/oauth-authorization-server")
echo "$prm" | grep -q '"scopes_supported"' && ok "protected-resource metadata served" || bad "protected-resource metadata missing: $prm"
echo "$asm" | grep -q 'registration_endpoint' && ok "authorization-server metadata has registration_endpoint" || bad "no registration_endpoint (Mcp::oauthRoutes() missing?)"
if [[ $https_expected == 1 ]]; then
  if echo "$prm$asm" | grep -q 'http:\\/\\/'; then bad "metadata advertises http:// behind https (trusted proxies not applied)"; else ok "metadata uses https"; fi
fi

echo "== 3. Dynamic client registration allowlist"
register() { curl -s -X POST "$BASE/oauth/register" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"client_name\":\"mcp-check\",\"redirect_uris\":$1,\"token_endpoint_auth_method\":\"none\"}"; }
g=user_bound_custom-mcp-check0000
declare -A cases=(
  [ChatGPT]='["https://chatgpt.com/connector/oauth/check123"]'
  [Gemini-app]="[\"https://oauth-redirect-sandbox.googleusercontent.com/r/$g\",\"https://oauth-redirect-test.googleusercontent.com/r/$g\",\"https://oauth-redirect.googleusercontent.com/r/$g\",\"https://oauth-redirect-sandbox.googleusercontent.com/a/$g\",\"https://oauth-redirect-test.googleusercontent.com/a/$g\",\"https://oauth-redirect.googleusercontent.com/a/$g\"]"
  [Claude.ai]='["https://claude.ai/api/mcp/auth_callback"]'
  [Localhost-CLI]='["http://localhost:53682/oauth/callback"]'
)
for name in "${!cases[@]}"; do
  r=$(register "${cases[$name]}")
  echo "$r" | grep -q '"client_id"' && ok "$name redirect accepted" || bad "$name redirect rejected: $r"
done
r=$(register '["https://evil.example/cb"]')
echo "$r" | grep -q 'invalid_redirect_uri' && ok "foreign redirect rejected" || bad "foreign redirect NOT rejected (redirect_domains has '*'?): $r"

echo "== 4. Signed-out authorize -> login page"
cid=$(register '["http://localhost:9999/callback"]' | sed -n 's/.*"client_id":"\([^"]*\)".*/\1/p')
loc=$(curl -s -o /dev/null -D - "$BASE/oauth/authorize?client_id=$cid&redirect_uri=http://localhost:9999/callback&response_type=code&scope=mcp:use&state=s&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256" | grep -i '^location:' | tr -d '\r')
[[ "$loc" == *login* ]] && ok "redirects to ${loc#*: }" || bad "expected redirect to login, got '${loc:-none}'"

if [[ -f artisan ]]; then
  echo "<?php echo Laravel\\Passport\\Client::query()->where('name', 'mcp-check')->update(['revoked' => true]), ' check clients revoked', PHP_EOL;" > storage/app/.mcp-check-revoke.php
  php artisan tinker --execute="require base_path('storage/app/.mcp-check-revoke.php');" 2>/dev/null | tail -1
  rm -f storage/app/.mcp-check-revoke.php
else
  echo "(not in a Laravel root: revoke clients named 'mcp-check' yourself)"
fi

echo; echo "Result: $pass passed, $fail failed"; [[ $fail == 0 ]]
