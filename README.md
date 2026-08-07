# TYPO3 Extension `Content Flow`

An editorial task board for TYPO3 v14. It puts a **backlog in front of TYPO3 workspaces** and
an **archive behind them**, and lets the workspace do the approval work in between.

Depends on TYPO3 core only — no third-party extensions.

```
 Backlog   Planned  │  In Progress   Review…   Ready  │  Done
 ─────────────────  │  ─────────────────────────────  │  ────────
    Content Flow    │   TYPO3 core workspace stages   │  Content Flow
```

**Tasks open themselves.** An editor who just opens a page and starts typing gets a workspace
version from TYPO3 and a task from Content Flow, without asking for either. When the version
goes live, the task closes with its history frozen into it.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the data model, the history-storage decision and the
accessibility commitments.

## Requirements

- [DDEV](https://ddev.com/) >= 1.24
- Docker

## Getting started

```bash
ddev start
```

That runs `composer install`, installs TYPO3 v14 and enables `typo3/theme-camino` (for demo
content to test against) plus `content_flow`, via post-start hooks. Then:

- Frontend: https://content-flow.ddev.site/
- Backend: https://content-flow.ddev.site/typo3/ (`admin` / `AdminPassword123!`)
- Module: **Web → Content Flow**

To see the workflow: create a workspace (with a review stage or two), switch into it, edit a
page, and watch the card appear on the board.

## Manual setup

```bash
ddev composer install
ddev composer typo3:setup
ddev composer typo3:demo-content
```

## Development

```bash
ddev composer cs:check      # PHP-CS-Fixer, dry-run
ddev composer cs:fix
ddev composer phpstan       # level 8
ddev composer test:unit
ddev composer test:functional
```

## Status

Early. Data model, state machine, auto-creation and publish/close are implemented; the board
renders read-only. TCA, Ajax write endpoints and the interactive board UI are still open — see
the end of [ARCHITECTURE.md](ARCHITECTURE.md).

## Compatibility

| Branch | TYPO3 | PHP |
|--------|-------|-----|
| main   | v14   | 8.2 – 8.5 |
