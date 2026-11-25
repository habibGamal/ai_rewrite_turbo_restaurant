# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Shared docs theme check: `scripts/docs-theme.sh` (verify / lock / pull) and `docs/.vitepress/theme.lock` keep the theme identical across the POS, website and platform docs; `docs-check.sh` fails when they differ. The style guide explains how to run the three sites together.
- Restructured `docs/` into the six-section Arabic-default layout (`start/`, `guide/`, `admin/`, `integrations/`, `dev/`, `dev/ops/`), added the shared VitePress theme with product switcher and client-side redirects, added `DOCS_PUBLIC=1` public build mode, generated user glossary from `.workspace/GLOSSARY.md`, added `docs/dev/style-guide.md` and `docs/dev/ops/runbooks.md`, and updated `scripts/docs-check.sh`.
- Unified technical and user documentation under `docs/`, removed stale Markdown plans/reports, created `.workspace/` for agent drafts/specs/glossary, updated `AGENTS.md`, and added `scripts/docs-check.sh` with CI verification.
