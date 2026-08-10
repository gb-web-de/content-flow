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

`typo3/theme-camino` ships a complete demo site in `Initialisation/data.xml` — pages *Camino*,
*FAQs*, *Packing List*, *Camino Route Comparison*, an imprint/privacy footer and images. TYPO3
imports it during `typo3 setup` when `TYPO3_SETUP_DISTRIBUTION=theme_camino` is set (see
[.ddev/config.yaml](.ddev/config.yaml)); this requires `typo3/cms-impexp`, and it is mutually
exclusive with `--create-site`/`TYPO3_SETUP_CREATE_SITE` — the distribution creates the site
configuration itself.

What no distribution can provide is a **workspace with custom review stages**, and without
those the board shows only the two fixed core stages. `content_flow` adds that:

```bash
ddev contentflow-demo
```

It verifies the Camino content arrived and creates the *Editorial* workspace with the stages
*Review* and *Approval*. Re-running is safe — existing data is kept, and recreating (which
deletes the workspace and any versions inside it) only happens after you confirm, or with
`--force`.

> During `ddev start` the same command runs non-interactively. DDEV hooks have no TTY, so it
> can only report and keep, never ask — use `ddev contentflow-demo` when you want the prompt.

`typo3/cms-styleguide` is also installed if you want bulk TCA test records on top (backend
module *System → Styleguide*).

## Manual setup

```bash
ddev composer install
ddev exec .Build/bin/typo3 setup --no-interaction --force --server-type=other
ddev exec .Build/bin/typo3 extension:setup
ddev contentflow-demo
```

(The `setup` call picks up database, admin user and `TYPO3_SETUP_DISTRIBUTION` from the
environment defined in [.ddev/config.yaml](.ddev/config.yaml).)

## Development

```bash
ddev composer cs:check      # PHP-CS-Fixer, dry-run
ddev composer cs:fix
ddev composer phpstan       # level 8
ddev composer test:unit
ddev composer test:functional
npm run test:js             # vitest, the extension's own ES modules
npm run test:e2e            # Playwright, against a served backend
```

The functional suite runs on SQLite in CI and needs no database service:
`typo3DatabaseDriver=pdo_sqlite composer test:functional`.

Playwright needs a running site and a backend login, both read from the
environment — there is no fixed default, because the DDEV project name is not
unique across worktrees:

```bash
npm ci && npx playwright install --with-deps chromium
CONTENTFLOW_BASE_URL=$(ddev describe -j | jq -r .raw.primary_url) npm run test:e2e
```

CI serves the same installation without DDEV: `typo3 setup` on SQLite,
`extension:setup` for the Camino content, `contentflow:democontent` for the
workspace, then PHP's built-in server via
[`Build/playwright/router.php`](Build/playwright/router.php).

## Status

Early. Data model, state machine, auto-creation and publish/close are implemented; the board
renders read-only. TCA, Ajax write endpoints and the interactive board UI are still open — see
the end of [ARCHITECTURE.md](ARCHITECTURE.md).

## Compatibility

| Branch | TYPO3 | PHP |
|--------|-------|-----|
| main   | v14   | 8.2 – 8.5 |
