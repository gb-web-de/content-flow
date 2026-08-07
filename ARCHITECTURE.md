# Content Flow — Architecture

Content Flow is a **standalone** TYPO3 v14 extension. It depends on TYPO3 core only
(`typo3/cms-workspaces`), and deliberately **not** on
`web-vision/kanban-workspaces` or `xima/xima-typo3-content-planner`. Those two were
studied as prior art; neither is installed, and none of their tables are read or written.

## The idea in one paragraph

TYPO3 workspaces already contain a complete approval engine: stages, permissions,
notifications, diffs, publishing. What they lack is a **before** and an **after** — there is
no way to say "this page needs work" until somebody actually edits it, and no trace left once
it goes live. Content Flow adds a **task** on either side of the version's lifetime, and gets
out of the way in the middle. Editors do not learn a workflow; the workflow follows them.

```
    ┌── Content Flow ──┐   ┌────── TYPO3 core workspace stages ──────┐   ┌ Content Flow ┐
    │                  │   │                                        │   │              │
    │ Backlog  Planned │   │ In Progress   Review 1..n   Ready       │   │ Done         │
    │                  │   │                                        │   │              │
    └──────────────────┘   └────────────────────────────────────────┘   └──────────────┘
      no version yet         a workspace version exists (t3ver_stage)      published,
                                                                          version gone
```

The middle section is read from `sys_workspace_stage`. An integrator defines review steps
where TYPO3 already expects them (Workspace record → Stages) and the board picks them up —
Content Flow has no stage configuration of its own to keep in sync.

This also answers, natively, what
[kanban-workspaces#31](https://github.com/web-vision/kanban-workspaces/issues/31) asked for
(stages *before* and *after* the fixed core ones): "before editing" is Backlog/Planned,
"after ready" is Done, and everything between is already freely definable in core.

## The four moments

**1. Somebody plans work.** A task is created for any record — `pages`, `tt_content`, or
anything else — and lands in Backlog. Assigning a `be_user` moves it to Planned. Editors may
assign themselves. No version exists yet, nothing is versioned, nothing is locked.

**2. Somebody edits.** The editor opens the page and types. TYPO3 auto-creates a workspace
version. `TaskAutoCreationDataHandlerHook` notices and moves the task to In Progress — **or
creates one on the spot if none existed**, so unplanned work is captured too. The editor is
never asked to "open a ticket"; there simply is one afterwards.

**3. The version walks the stages.** Core does this entirely. Dragging a card is translated
into a normal core stage transition, so permissions, recipients and stage comments behave
exactly as they do in the Workspaces module. Content Flow mirrors the resulting
`t3ver_stage` onto the task as a read cache for sorting; core stays the source of truth.

**4. It goes live.** `CloseTaskAfterPublishListener` closes the task on
`AfterRecordPublishedEvent`. The record now has no version, so the task's history is frozen
at that moment (below) and the card leaves the board.

## Where the history lives

An earlier draft of this document claimed the trail breaks when a version is published, and
snapshotted a copy of `sys_history` at publish time to compensate. **That was wrong on both
counts**, and the corrected reasoning is what the code now does:

- **Publishing does not lose anything.** `RecordHistoryStore::publishRecord()` calls
  `migrateWorkspaceHistory()`, which rewrites `sys_history.recuid` from the version uid to the
  live uid. Every entry survives and stays reachable from the live record — including the
  `ACTION_STAGECHANGE` rows that carry each stage comment and its recipients.
- **Nothing deletes those rows at publish time either.** Nothing in core does.
- **What actually loses them is age.** EXT:scheduler registers `sys_history` in the table
  garbage collection task with an `expirePeriod` of **30 days** by default. A task archived
  today has no trail left in a month or two.

So the real question was never "duplicate or not" — it is that `sys_history` is a *volatile
operational log*, while a closed task is an *archive record*. Writing the decision down is
therefore not a second truth; it is the only durable one:

| | Stored where | Why |
|---|---|---|
| **Comments** | own table `tx_contentflow_comment` | must be queryable (@mentions, "unresolved" filters, dashboards) and concurrently writable. A JSON blob on the task means read-modify-write races and no indexes. |
| **Decisions** (assigned, moved from stage X to Y, with comment) | own table `tx_contentflow_activity`, append-only, written **when it happens** | these must outlive the 30-day GC. Kept small: who, when, from/to, comment. |
| **Field-level before/after values** | **not copied** — `activity.history_uid` points at the `sys_history` row | bulky, and for the common case (one edit, straight to live) the row is still there. |

The pointer is the part that makes both halves work, and it is exactly the right granularity:
where the `sys_history` row still exists you get the full field-level detail for free; once the
GC has taken it, the decision itself is still on record. **A dangling `history_uid` means
"detail expired", never an error** — readers must degrade, not fail.

Because decisions are logged as they happen rather than reconstructed at the end, they also
carry the correct user and timestamp. Transitions performed outside the board (in the
Workspaces module) are not observed live; `ActivityLogger::findStageChanges()` reconciles those
from `sys_history` on read.

## Concurrency

Two editors opening the same page at the same time must not produce two tasks. This is
enforced by a **unique key** (`record_table, record_uid, closed, deleted`), not by a
read-then-write check: the loser of the race catches the constraint violation and adopts the
winner's task. Check-then-insert would let both pass the check (this exact TOCTOU bug exists
in `kanban-workspaces`' `AssigneeMappingService`, found during the 2026-08-07 review).

`closed` participates in the key so a record can accumulate many closed tasks over its
lifetime while only ever having one open.

## UX and accessibility commitments

"Editors should barely have to think" is a design constraint, not a nice-to-have. Concretely,
binding for every board feature:

- **Nothing is drag-only.** Every card move is reachable from the keyboard and from a menu on
  the card. Drag-and-drop is an accelerator, never the only path. (This is the single most
  common Kanban accessibility failure.)
- **The board announces itself.** Moves, assignments and errors go through an ARIA live
  region, so a screen-reader user hears "Moved *About us* to Review" rather than silence.
- **Status is never colour alone** — always icon *and* label. Required for WCAG 1.4.1, and it
  also survives greyscale printing and colour-blind editors.
- **Focus is never lost.** After a move, focus follows the card. Modals trap focus and return
  it to the trigger on close.
- **`prefers-reduced-motion` is respected** for all card transitions.
- **Publishing is not a drop target.** Going live is irreversible, so it is an explicit action
  with a confirmation, never something an editor can do by dropping a card slightly off target.
- Target: **WCAG 2.2 AA**, verified with keyboard-only and screen-reader passes before any
  release.

## Carried over from the kanban-workspaces review (2026-08-07)

Baked in from the start rather than fixed later:

- Unique constraints instead of check-then-insert (see Concurrency).
- One query per board, cards grouped in PHP — adding review stages never adds queries. The
  reviewed code had an N+1 in `AssigneeEnrichmentListener` (up to 4 queries × N cards).
- All `QueryBuilder` input parameter-bound; restrictions applied explicitly.
- When the board gains inline JSON config, encode with
  `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` — the reviewed code had a
  stored-XSS path via `json_encode()` into an inline `<script>` block.
- Every Ajax endpoint validates the target record and the user's rights on it server-side,
  and never trusts a client-supplied workspace id — the reviewed `AssignAjaxController` did.
- DocHeader configured properly, so the page-tree toggle keeps working
  ([kanban-workspaces#43](https://github.com/web-vision/kanban-workspaces/issues/43)).

## Verified core API assumptions

Checked against core sources rather than assumed — worth re-checking on core updates:

- `AfterRecordPublishedEvent::getRecordId()` is the **live** uid in both publish paths
  (`DataHandlerHook::publishVersion` swap path and `publishNewRecord`).
- `RecordHistoryStore::publishRecord()` → `migrateWorkspaceHistory()` re-points the version's
  `sys_history` rows at the live uid, so the trail survives publishing without help. Note this
  runs *after* `AfterRecordPublishedEvent` is dispatched — an earlier design snapshotted
  history in that listener and was therefore both redundant and racing core.
- `sys_history` is garbage-collected after **30 days** by default
  (EXT:scheduler `TableGarbageCollectionTask`). This, not publishing, is what an archive has
  to survive.
- Core dispatches **no** event for "a workspace version was created". Version creation must
  be observed via a DataHandler hook; `DataHandler::getAutoVersionId()` is public API and is
  how the hook learns which version an update was redirected into.
- `WorkspaceRepository::findByUid()` **throws** `\RuntimeException` when the workspace is
  missing — it does not return `null`.
- `StagesService::STAGE_EDIT_ID` = 0, `STAGE_PUBLISH_ID` = -10,
  `STAGE_PUBLISH_EXECUTE_ID` = -20. The last is a core implementation detail and is never
  shown as a column.

## Status

Implemented: data model, state machine, column registry, auto-creation hook, publish/close
listener, read-only board rendering.

Not yet implemented: TCA for the task/comment tables, the Ajax write endpoints, the Lit board
UI with the accessibility commitments above, and the Dashboard widget.
