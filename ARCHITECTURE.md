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

## What a task is about

Every **versionable** record can be tracked — if a table has no workspace support it never
produces a version, so there is nothing for the workflow to move. Among those, Content Flow
distinguishes two roles:

- A **subject** is page-like: it stands on its own and gets its own card. `pages`, obviously.
  But not only: a *news* record is technically a record while behaving like a page, because it
  has its own detail view. To an editor it *is* a page. Which tables count is configuration
  (`$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['content_flow']['subjectTables']`), because only the
  integrator knows their content model.
- Everything else is **page-bound**: a content element belongs to the page it sits on.

**A task on a page pulls in everything on that page.** One card means "this page and its
content", not one card per content element — otherwise editing a page with twelve elements
would bury the board in twelve identical-looking cards.

**But the editor can pull an element out** and give it a task of its own, when one banner or
one accordion really is a separate piece of work.

That split is enforced with a single rule, no flags: **a record belongs to at most one open
task**, as a unique key on the membership table. Detaching moves the membership row to the new
task; re-syncing the page's task then *cannot* reclaim the element, because the slot is taken.
The same key is what stops two editors opening the same page from creating two tasks.

## Content that lives somewhere else

A shortcut element pulls content in from another page. An editor working on page A
changes it — and page B silently changes too. **A board that hides this is a task list;
a board that shows it is a planning tool.** So every member carries two facts:

- `home_pid` — the page the record actually lives on. When it differs from the task's
  subject, the editor is working on foreign content.
- `shared` — other pages reference this record.

Both are surfaced as a warning on the card, and both are advisory: nothing is blocked.
The editor decides whether the change belongs to **their own** task (they are working on
page A, so track it there) or gets **its own** task (it is really a change to page B's
content) — `moveMemberToTask()` and `detachIntoOwnTask()` are the two operations.

`ReferenceInspector` is built on **`sys_refindex`, not on `CType='shortcut'`**. The
reference index already records every kind of reuse — shortcuts, inline relations, links,
file references — so this catches reuse a shortcut check would miss, and it keeps working
when an extension invents its own relation type. The tradeoff: the index is rebuilt by a
scheduler task and can be stale, so a negative answer means "no reuse known", not proof.
That is acceptable precisely because the result is a warning rather than a gate.

## The wizard

"Not straight to a new task on direct editing — a wizard: new task, or add to task."

The constraint that shapes this: the hook runs **server-side inside DataHandler, during a
save**. It cannot open a dialog. And blocking the first keystroke behind a modal would
contradict the whole premise that editors should barely have to think.

So the wizard is **post-save and non-blocking**:

1. The editor edits. A task is created as before and flagged `auto_created = 1` — the
   work is captured no matter what happens next, which is the important part.
2. A backend notification offers the choice: *keep this task*, or *add to an existing
   task* (with the open tasks in scope offered as targets).
3. Ignoring it is a valid answer. The sensible default already happened.

This keeps the "unplanned work is never lost" guarantee while still giving the editor the
say. `auto_created` is what lets the board distinguish "somebody planned this" from "this
appeared because someone started typing" — and it is what the notification keys off.

> Status: the flag, the merge operation (`moveMemberToTask`) and the detach operation are
> implemented. The notification UI itself is not yet built.

## The four moments

**1. Somebody plans work.** A task is created for a subject and lands in Backlog, taking the
page's content along with it. Assigning a `be_user` moves it to Planned. Editors may assign
themselves. No version exists yet, nothing is locked.

**2. Somebody edits.** The editor opens the page and types. TYPO3 auto-creates a workspace
version. `TaskAutoCreationDataHandlerHook` notices and moves the task to In Progress — **or
creates one on the spot if none existed**, so unplanned work is captured too. The editor is
never asked to "open a ticket"; there simply is one afterwards.

The hook routes the edit rather than blindly creating: an existing membership wins (so a
detached element stays with its own task), otherwise the subject is resolved — the record
itself when page-like, else the page it sits on.

**3. The version walks the stages.** Core does this entirely. Dragging a card is translated
into a normal core stage transition, so permissions, recipients and stage comments behave
exactly as they do in the Workspaces module. Content Flow mirrors the resulting
`t3ver_stage` onto the task as a read cache for sorting; core stays the source of truth.

**4. It goes live.** `CloseTaskAfterPublishListener` closes the task on
`AfterRecordPublishedEvent` — but only once **nothing the task covers is still pending**. The
event fires per record, and a task covers a page and all its content, so closing on the first
published element would archive a task with half its content still in review.

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
enforced by the **unique key on the membership table**
(`record_table, record_uid, closed, deleted`), not by a read-then-write check: the loser of
the race catches the constraint violation, discards its own task and adopts the winner's.
Check-then-insert would let both pass the check (this exact TOCTOU bug exists in
`kanban-workspaces`' `AssigneeMappingService`, found during the 2026-08-07 review).

`closed` participates in the key so a record can accumulate many closed tasks over its
lifetime while only ever belonging to one open one. That single key does triple duty: it
prevents duplicate tasks, makes detaching permanent, and keeps aggregation idempotent.

## What the two reference extensions do, and what we take from it

Studied to decide scope, not to depend on.

**kanban-workspaces — backend module.** Columns are workspace stages; cards are workspace
records. A card shows: record type icon, title, UID, page name, last-modified date, language
flag, assignee avatars, comment and attachment counts, due date/schedule badges, and an
"integrity" warning line. Drag-and-drop routes a drop through
`sendToSpecificStageWindow/Execute` — i.e. the drop *proposes* a stage transition and core's
send-to-stage form (recipients, comment) confirms it. It has **no dashboard**.

**xima content-planner — status everywhere.** Its reach comes from being present where
editors already are, not from one module: a **context menu item provider** puts status and
assignee on right-click in the page tree and record lists, plus badges in the page tree, and
four **dashboard widgets** (status overview, content status, updates, comments).

**What that means for Content Flow.** The card content list above is essentially a
requirements list — we have title and subject, and still owe: type icon, assignee, comment
count, due date, language, and the cross-page warning. The two lessons worth copying are
kanban-workspaces' *drop proposes, core confirms* (never write a stage directly) and xima's
*meet editors where they are* (context menu on the page tree, so a task can be planned without
opening the board at all).

## Core APIs first

Everything TYPO3 already solves is delegated. Verified present in v14.3:

| Need | Core module — no custom code |
|---|---|
| Pick a page for the "+" button (tree, **live search**, depth) | `wizard_element_browser` route + `@typo3/backend/tree/page-browser.js` |
| Multi-step "new task" flow | `@typo3/backend/multi-step-wizard.js` (`addSlide`, `lockNextStep`, `addFinalProcessingSlide`) |
| Select records → run an action ("select to task") | `@typo3/backend/multi-record-selection.js` + `multi-record-selection-action.js` |
| Right-click a page → plan a task | `TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider` (the xima pattern) |
| Dialogs, confirmations | `@typo3/backend/modal.js`, `@typo3/backend/severity.js` |
| Feedback | `@typo3/backend/notification.js` |
| Icons | `@typo3/backend/icons.js`, `<core:icon>` |
| Record writes | `@typo3/backend/ajax-data-handler.js` / DataHandler |
| Content drag-and-drop reference | `@typo3/backend/layout-module/drag-drop.js` |
| CSRF + session on write endpoints | backend ajax routes (`Configuration/Backend/AjaxRoutes.php`) |

Custom code is limited to what core has no opinion about: the board's own column-to-column
drag-and-drop, and the extension's ajax endpoints.

## Stage changes go through core

A drop onto a review column **proposes** a move; TYPO3 **decides** it. The board
never writes `t3ver_stage` itself.

Concretely, `executeStageAction` resolves the task's pending versions and hands them
to `DataHandler` as a `version` / `setStage` command. EXT:workspaces'
`version_setStage()` then does what we must not reimplement:

- `workspaceCannotEditOfflineVersion()` and `hasPermissionToUpdate()` on the page,
- **`workspaceCheckStageForCurrent()`** - whether this user may move *out of* the
  current stage at all,
- writes `t3ver_stage`,
- records the transition, its comment and its recipients in `sys_history`,
- queues the stage notification mails.

If `DataHandler::$errorLog` is non-empty, nothing of ours is written: our state must
not drift away from what core actually did. Only afterwards is the task row updated
(a read cache for the board) and the activity entry written (the durable record, since
`sys_history` is garbage-collected after 30 days).

An earlier version of this action simply `UPDATE`d our own table. It skipped every
check, every notification and core's entire history - and looked like it worked.

The distinction that makes this workable: Backlog, Planned and Done are **Content
Flow's own** states, which exist precisely because core has no notion of "not
versioned yet". Those are written directly. Everything between them belongs to core.

## Select to task, split from task

Two operations on the same invariant — *a record belongs to at most one open task*:

- **Select to task** (`contentflow_task_attach`): the selected records are moved onto a task.
  Already-claimed records are *moved*, not duplicated.
- **Split from task** (`contentflow_task_detach`): one record is pulled out into a task of its
  own. Confirmed first, and never a side effect of a drag — see below.

Both endpoints re-derive permissions server-side: the workspace comes from the backend user
(never the request), the table must be trackable, and `doesUserHaveAccess()` +
`recordEditAccessInternals()` are checked on the concrete record. A client-supplied workspace
id was a real IDOR in the reviewed `AssignAjaxController`.

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

Audited against the code on 2026-08-07, not written from memory.

**Implemented and covered by tests**

- Data model: subject + members, with the single unique key that prevents duplicate
  tasks, makes detaching permanent and keeps aggregation idempotent.
- State machine, column registry (core stages + Content Flow's own columns), subject
  registry (configurable page-like tables), page aggregation, cross-page/reuse
  detection via `sys_refindex`.
- Auto-creation on edit (`TaskAutoCreationDataHandlerHook`) and publish/close
  (`CloseTaskAfterPublishListener`), including "close only when nothing is pending".
- **Stage changes routed through core** (`version` / `setStage` on DataHandler), with
  tests asserting on core's side effects: `t3ver_stage`, the `sys_history` entry with
  its comment, and core refusing a stage change on a live record.
- Ticket view: covered records with per-record icons, cross-page warnings, one merged
  timeline with comments anchored to the action they explain, and core-rendered diffs.
- Board: cards, filters, column drag-and-drop, assign-to-me, keyboard-operable
  selection, live-region announcements.
- "+ New task": core element browser for the page, then core `MultiStepWizard` for
  title / priority / assignment.
- Three Dashboard widgets on v14's `WidgetRendererInterface`.
- Page module banner via `ModifyPageLayoutContentEvent`.
- Ajax endpoints: create, attach, detach, move-stage, execute-stage, assign-me,
  details, ticket, wizard-pending, wizard-submit - each re-deriving permissions
  server-side.

**Not implemented**

- **TCA for `tx_contentflow_task` / `_task_item` / `_comment` / `_activity`.** Only
  `Configuration/TCA/Overrides` exists. The tables are therefore not editable in the
  backend, and every base column is declared by hand in `ext_tables.sql` because the
  schema analyzer has no TCA to derive them from.
- **Context-menu item provider** for planning a task by right-clicking the page tree.
  Listed under "meet editors where they are" as a lesson taken from the xima
  content-planner - the lesson is recorded, the code is not written.
- **Writing comments from the ticket view.** Comments render, and stage comments are
  captured, but there is no comment form; the ticket is read-only apart from the
  actions in its header.
- **Notification/@mention system**, dashboard notification feed, and the Visual Editor.
- **Automated coverage for the JavaScript.** The PHP side is tested; the board modules
  are only syntax-checked.

**Verification**

Unlike an earlier version of this document, this is no longer "verified by reading":
`ddev` runs the instance, and the suite (PHPUnit functional + unit, PHPStan level 8,
php-cs-fixer) runs green against it. Several bugs listed in this document were found
only by executing - notably the auto-creation hook silently doing nothing, and a
template that could not be parsed while every test stayed green.
