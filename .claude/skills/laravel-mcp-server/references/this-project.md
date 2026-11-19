# Notes for this repository (Turbo POS, `larament/`)

Read with SKILL.md. Where this file and the skill differ, this file wins for this repo.

- **Laravel 11, not 13.** Pin `laravel/mcp:~1.1.2` (1.2+ requires Laravel 12) and
  `laravel/passport:~13.11.0` (13.12+ requires Laravel 12). Both resolve against our lock
  (checked 2026-11-19). Unpin when the app moves to Laravel 12.
- **No `laravel/ai`.** There is nothing to bridge: write native `Laravel\Mcp\Server\Tool`
  classes that call the existing report services (`app/Services/**`, `app/Core/Orders/SalesBreakdown.php`,
  `app/Services/Reports/ReturnsAggregates.php`, `ReportTerminalScope`). Skip `BridgedTool`.
- **Login is Breeze + Inertia (`routes/auth.php`, `AuthenticatedSessionController`), not Fortify.**
  Apply checklist item 5 there: return `Inertia::location($url)` when the intended URL path
  starts with `/oauth/`. Badge (barcode) login must not be able to approve OAuth.
- **Auth guard:** only `web` exists (Sanctum installed). Add `api` => passport. Nothing uses `auth:api`.
- **Permissions:** Spatie, all on `web`. `User::hasPermissionTo` also applies
  `FeatureService::isPermissionDisabled` (god-mode) and lets the vendor through. Add
  `protected string $guard_name = 'web';`.
- **Vendor user** is hidden by `HidesVendorScope`; resolve users with `withoutGlobalScopes()` only where needed.
- **Business date:** orders use `shifts.business_date` via `orders.shift_id`; never filter by `created_at`.
  `APP_TIMEZONE` defaults to UTC.
- **Gating:** new god-mode feature in `App\Enums\OptionalFeature` + permission(s) in its `permissions()`.
- **In-app text** (consent page, settings, errors shown to users) must be Modern Standard Arabic.
- Plan doc: https://claude.ai/artifact/9V78qdttfEzrqe9q7xSRNp
- **Remote only** (Habib, 2026-11-19): no `Mcp::local` / stdio server for this app. Skip SKILL.md section 1's stdio steps; test tools with Pest and connect clients (incl. Claude Code via `claude mcp add --transport http`) to `/mcp/pos`.
- **Layout (as built):** tools extend `App\Mcp\Tools\BusinessTool` (report-page gate, one JSON envelope,
  call log). Register new tools in `BusinessServer::TOOLS`. Playbook prompts are Markdown files in
  `resources/ai/playbooks/*.md`; their `requires:` front matter names the tools they need, and the prompt is
  listed only when the user may call all of them. No `SkillPrompt` class. Resources: `pos://glossary`
  (`resources/ai/glossary.md`) and `pos://business-profile`. Answer eval set: `docs/ai/mcp-evaluation.md`.
