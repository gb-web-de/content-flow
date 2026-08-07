# Content Flow — Architecture & Merge Concept

Content Flow is not a rewrite of either source project. It is a thin **presentation and
workflow-glue layer** that puts one Kanban board UI in front of two data sources that were
never meant to compete with each other:

| | [web-vision/kanban-workspaces](https://github.com/web-vision/kanban-workspaces) | [xima/xima-typo3-content-planner](https://github.com/xima-media/xima-typo3-content-planner) |
|---|---|---|
| Operates on | **versioned** records in a TYPO3 **Workspace** | **live** pages/records, no versioning needed |
| Column source | `sys_workspace_stage` (workspace stages) | `tx_ximatypo3contentplanner_domain_model_status` |
| Strength | drag-and-drop stage transitions, diff/preview, "send to stage" | status badges everywhere (page tree, record list, dashboard), assignees, threaded comments |
| Gap | no concept of editorial status outside of workspaces | no Kanban board, no drag-and-drop |

Editors rarely work exclusively in one of these worlds. Small changes get a status directly on
Live (`xima_typo3_content_planner`); larger campaigns/migrations go through a Workspace with
approval stages (`kanban-workspaces`). **Content Flow renders both as the same board component,
switching data source based on context.**

## Mode switch

`Classes/Service/BoardModeResolver.php` is the whole trick:

```
Live workspace (workspace = 0)      -> MODE_STATUS  -> xima's status field on `pages`
Non-Live workspace (workspace != 0) -> MODE_STAGE    -> core `sys_workspace_stage`
```

The board component doesn't know which mode it's in beyond the column/card shape it's handed —
same as kanban-workspaces' `board.js` already doesn't care whether a "stage" was a default TYPO3
stage or a custom one.

## Ownership boundary (do not duplicate persistence)

Content Flow **reads** and, in later milestones, **writes through** to the two extensions' own
APIs. It never introduces a competing status/assignee/comment table or a competing stage table:

- Status/assignee/comments: owned by `xima_typo3_content_planner`
  (`tx_ximatypo3contentplanner_domain_model_status`, the `tx_ximatypo3contentplanner_status` /
  `_assignee` / `_comments` fields added to `pages`, and `tx_ximatypo3contentplanner_comment`).
  Content Flow only reads these in M1 (`StatusBoardRepository`); M3 writes through
  `DataHandler`, the same way `xima_typo3_content_planner`'s own edit forms do.
- Stages: owned by TYPO3 core (`typo3/cms-workspaces`), exactly as in `kanban-workspaces`.

This means upgrading either dependency doesn't force a Content Flow migration, and Content Flow
extensions its dependencies rather than forking them.

## Milestones

**M1 — done in this scaffold.** Composer+DDEV dev environment, backend module skeleton, DocHeader
wired correctly from the start (see "Lessons carried over" below), read-only status board for
Live pages via `StatusBoardRepository`. Stage mode is resolved but renders a placeholder.

**M2 — port the Lit board.** Bring over `kanban-workspaces`' `Resources/Public/JavaScript`
Lit components (`board.js`, `column.js`, `card.js`, `filter-sidebar.js`, `preview-modal.js`) and
its TypeScript/SCSS build (`Build/Sources/TypeScript`, `Build/Sources/Sass`, see
`kanban-workspaces` PR #39/#40). Swap `data/WorkspaceApi.js` for a small adapter interface with
two implementations:
  - `StageApi.js` — thin wrapper around the existing `WorkspaceApi.js` (stage transitions via
    `sendToSpecificStageExecute`, unchanged from kanban-workspaces).
  - `StatusApi.js` — new; PATCHes `tx_ximatypo3contentplanner_status` via an Ajax route that goes
    through `DataHandler` (`TCEmain`), the same write path xima's own inline edit forms use, so
    permission checks, workspace placeholders and hooks all still apply.

**M3 — collaboration layer.** `kanban-workspaces` issue #38 (interactive/auditable checklists,
PR #46) and issue #32 (@mentions + Jira-style preview, PR #48) both landed as **card-preview UI
features** in `kanban-workspaces`, not as new persistence — reuse that UI, but point the
@mention resolver and comment submission at `xima_typo3_content_planner`'s own
`tx_ximatypo3contentplanner_comment` table (via its Ajax endpoints) instead of introducing a
third comment table. Checklists (per stage × card) stay kanban-workspaces-owned, since xima has
no equivalent concept.

**M4 — flexible columns.** `kanban-workspaces` issue #31 (fully custom/reorderable stage sets,
no work started upstream) becomes a Content Flow-native feature for stage mode: an
`ext_conf`/TCA-driven stage set per workspace. Status mode already has this for free — xima's
`tx_ximatypo3contentplanner_domain_model_status.sorting` is already freely orderable/insertable,
no core change needed.

**M5 — dashboard.** Surface a compact board summary as a TYPO3 Dashboard widget, using
`typo3/cms-dashboard` the same way `xima_typo3_content_planner` already does for its own widgets
— gives Content Flow a "my open cards across all pages" view outside the board module.

## Lessons carried over from the kanban-workspaces review (2026-08-07)

Baked into this scaffold from day one instead of being fixed later:

- **DocHeader configured properly** (`ContentFlowController::indexAction`, title/breadcrumb/
  shortcut) — `kanban-workspaces` v1 replaced the module header outright and broke page-tree
  navigation ([issue #43](https://github.com/web-vision/kanban-workspaces/issues/43)).
- **Parameterized `QueryBuilder` everywhere**, restrictions applied explicitly
  (`StatusBoardRepository`) — the review found unvalidated client input reaching persistence in
  `AssignAjaxController`.
- When M2 adds inline JSON config to the page (`window.ContentFlowConfig = ...`), encode with
  `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` — the review found a stored-XSS
  path via `json_encode()` without hex flags in `kanban-workspaces`' inline script block.
- Batch queries for grid enrichment (avoid the N+1 pattern the review found in
  `AssigneeEnrichmentListener`) — `StatusBoardRepository::getCardsGroupedByStatus` already
  fetches all cards for a page in one query instead of one query per card.
- Any future assignment/mapping table gets a real unique constraint
  (`workspace_id, table_name, record_uid` or equivalent) with `INSERT ... ON DUPLICATE KEY
  UPDATE`, not update-then-conditional-insert — the review found a TOCTOU duplicate-row bug in
  `AssigneeMappingService`.

## Dev environment

```bash
cd /home/gordon/Projekte/content-flow
ddev start
```

`ddev start` runs `composer install`, `composer typo3:setup` (installs TYPO3 v14, creates the
site) and `composer typo3:demo-content` (enables `typo3/theme-camino` for ready-made demo pages,
`xima_typo3_content_planner`, and `content_flow` itself) via post-start hooks — one command to a
working editorial board with real content to test against. See `README.md` for manual steps and
troubleshooting.
