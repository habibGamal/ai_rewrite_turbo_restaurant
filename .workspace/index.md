# Workspace (`.workspace/`)

Everything AI agents draft lives under `.workspace/`, never in the repo root or `docs/`. Only finished outcomes move into `docs/`. When a spec ships, move its lasting facts into `docs/` in the same PR and move the spec folder into `.workspace/archive/`.

## Layout

- `index.md` — this overview and the rules for agent drafts.
- `GLOSSARY.md` — domain glossary mapping English code names to Arabic UI terms and meanings.
- `CODING_STANDARDS.md` — real codebase conventions and quality gates.
- `agents/issue-tracker.md` — pointer to local Markdown specs, tickets and maps.
- `agents/domain.md` — pointer to the glossary and Architecture Decision Records.
- `adr/` — Architecture Decision Records (`NNNN-slug.md`).
- `specs/` — active feature specs (`<feature>/spec.md`), issue tickets (`<feature>/issues/NN-slug.md`), and maps (`<effort>/map.md`).
- `out-of-scope/` — deferred or rejected ideas kept for reference.
- `research/` — technical investigations and spikes.
- `retros/` — post-effort retrospectives.
- `handoffs/` — session handoff notes between agents or humans.
- `wiki/` — working notes that do not yet belong in `docs/`.
- `archive/` — completed specs moved here when shipped.
- `prototypes/` — throwaway prototype code; contents are gitignored except `prototypes/README.md`.
