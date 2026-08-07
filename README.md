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

[`.ddev/scripts/post-start.sh`](.ddev/scripts/post-start.sh) then runs `composer install`,
installs TYPO3 v14 (once — it is guarded, not re-run on every start), sets up the extensions,
and creates demo content. It aborts loudly on failure rather than leaving a half-built
instance behind.

- Frontend: https://content-flow.ddev.site/
- Backend: https://content-flow.ddev.site/typo3/ (`admin` / `Password.1`)
- Module: **Web → Content Flow**

**To see the workflow:** switch into the *Editorial* workspace (created by the demo step, with
two review stages), open one of the demo pages, and change something. A card appears on the
board on its own — that is the point of the extension.

### About demo content

`typo3/theme-camino` does **not** ship demo content. On a fresh install it creates a site and
one empty page; the theme provides page/content-element rendering, not a populated page tree.
So `content_flow` brings its own:

```bash
ddev exec .Build/bin/typo3 contentflow:democontent
```

That creates a few demo pages plus a workspace with two review stages — the minimum needed for
the board to show anything. `typo3/cms-styleguide` is also installed if you want bulk TCA test
records on top (backend module *System → Styleguide*).

## Manual setup

```bash
ddev composer install
ddev exec .Build/bin/typo3 setup --no-interaction --force --server-type=other
ddev exec .Build/bin/typo3 extension:setup
ddev exec .Build/bin/typo3 contentflow:democontent
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
