# TYPO3 Extension `Content Flow`

Editorial Kanban board for TYPO3 v14 that unifies:

- **[xima/xima-typo3-content-planner](https://github.com/xima-media/xima-typo3-content-planner)**
  page statuses (for everyday Live editing), and
- **[web-vision/kanban-workspaces](https://github.com/web-vision/kanban-workspaces)**-style
  workspace stages (for approval workflows / migrations),

behind one board UI. See [ARCHITECTURE.md](ARCHITECTURE.md) for the full merge concept and
milestone plan — this scaffold currently ships milestone 1 (status-mode board, read-only).

## Requirements

- [DDEV](https://ddev.com/) >= 1.24
- Docker

## Getting started

```bash
git clone <this-repo> content-flow
cd content-flow
ddev start
```

`ddev start` triggers `composer install`, installs TYPO3 v14 (`composer typo3:setup`) and enables
the demo theme + both editorial extensions (`composer typo3:demo-content`) automatically via
`.ddev/config.yaml` post-start hooks. Once it finishes:

- Frontend: https://content-flow.ddev.site/
- Backend: https://content-flow.ddev.site/typo3/ (`admin` / `AdminPassword123!`)
- Module: **Web → Content Flow**, on any page with an `xima_typo3_content_planner` status set.

## Manual setup (if you skip the DDEV hooks)

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

## Compatibility

| Branch | TYPO3 | PHP |
|--------|-------|-----|
| main   | v14   | 8.2 – 8.5 |
