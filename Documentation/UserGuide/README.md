# Content Flow — Editor's Guide

*A tour of the whole task lifecycle, from "New task" to published — with real
screenshots from a working board.*

Content Flow puts a lightweight to‑do list **in front of** TYPO3's workspace
review process, and a closed, permanent record **behind** it. You never have
to learn a separate tool: you plan the work here, and TYPO3's own Editor,
Layout and Records views are where you actually do it. Content Flow just
follows along and keeps the board honest.

```
 Backlog   Planned  │  Editing   Review …   Ready to publish  │  Done
 ─────────────────  │  ──────────────────────────────────────  │  ────────
    Content Flow    │        your TYPO3 workspace stages        │  Content Flow
   (not versioned)   │        (however many you configured)      │ (published)
```

The middle section is **not** something Content Flow invents — it is read
directly from your workspace's own stages (*Settings → Workspaces*). This
demo installation has two: **Review** and **Approval**. Yours may have more,
fewer, or different names; everything below still applies, just relabel the
middle columns in your head.

---

## 1. Creating your first task

Open **Web → Content Flow** on any page and click **+ New task**. You get a
choice of four starting points:

![The "how do you want to start?" choice: plan a new page, pick an existing page, select a record, or create a new record](Images/02-new-task-entry-choice.png)

- **Create a new page** — plans a page that does not exist yet. Nothing is
  created in the page tree until the ticket is actually dragged into
  *Editing* — dropping it there opens TYPO3's own page wizard.
- **Edit an existing page** — the common case: pick a page from the tree.
- **Select a record** — for anything else worth tracking on its own (a
  content element, a news item, whatever your installation registered as
  trackable).
- **Create a new record** — opens TYPO3's own "new record" wizard directly.

Whichever you pick, the same small form follows:

![Task details form: title pre-filled from the page, description, assignment, priority, start/due date](Images/03-new-task-details-form.png)

- **Title** defaults to the page's own title — you rarely need to type one.
- **Assignment** — assign to yourself, leave it open for anyone to pick up
  ("up for grabs" is a real planning state, not a missing value), or hand it
  straight to a colleague.
- **Priority**, and optional **start/due dates**. Setting a start date moves
  the new card straight into *Planned* instead of *Backlog* — a start date
  is a commitment, and the board shows it as one immediately.

Finish the wizard, and the card lands on the board:

![A new card sitting in the Backlog column, assigned to Erin Editor](Images/04-board-backlog-card.png)

**Backlog vs. Planned** are Content Flow's own columns — TYPO3's workspaces
have no concept of "not started yet", so this is the part Content Flow adds
in front. Moving a card between the two is just a drag, no dialog:

![The same card, now in the Planned column after a drag](Images/05-board-card-planned.png)

---

## 2. Where else a task shows up: the page itself

You don't have to keep the board open. Open the page in **Web → Layout**
and a banner tells you what's going on, right where you're about to work:

![The Content Flow banner in the Layout module, showing the task, its state, and its assignee](Images/06-layout-banner-planned.png)

If a page has **no** open task yet, the banner offers **Plan task for this
page** instead — same wizard, one click closer.

---

## 3. The magic part: tasks open (and advance) themselves

This is the thing that makes Content Flow different from a normal
kanban board: **you don't have to move the card yourself for the first
step.** The moment you actually edit something — in the Layout module, the
Visual Editor, or a plain record form — TYPO3 creates a workspace version of
it, and Content Flow notices and moves the task into **Editing** for you.

Watch what happens after saving one small change to a page that was sitting
in *Planned*:

![The card automatically sitting in the Editing column right after a save — nobody dragged it there](Images/07-board-card-auto-editing.png)

Nobody dragged that card. It advanced itself because a real edit happened.
The same page's banner and content elements pick up the same signal —
notice the little coloured badge on the paragraph below, matching the task's
own colour:

![The Layout banner now shows "in_progress", and the edited paragraph carries a matching coloured badge naming the task](Images/08-layout-banner-inprogress-badge.png)

That colour is not decoration. **The same hue follows one task everywhere**
— board card, page banner, content-element badge, and the Visual Editor
markers below — so "the orange one" means the same task no matter which
screen you're looking at.

---

## 4. Working in the Visual Editor

Open the same page in **Web → Editor** (the Visual Editor) and a **Task**
select appears in the toolbar, plus a small legend of every task touching
this page:

![The Visual Editor toolbar with a "Task" select showing the active task, and the edited paragraph outlined in the task's colour with a small clickable bubble](Images/09-visual-editor-task-select-marker.png)

What this gives you that a plain "it advances itself" cannot:

- **Declare the task *before* you start typing.** The Visual Editor
  autosaves every few keystrokes, so waiting for the first save to imply a
  task would be too late and too noisy. Pick it from the dropdown first.
- **See who else's work you're standing on.** Every content element already
  claimed by a task gets an outline in that task's colour, plus a small
  bubble in the corner. Hover the legend to highlight exactly which parts of
  the page belong to which task — useful the moment two people touch the
  same page.
- **Click a bubble (or a legend swatch) to open that task as a ticket**,
  right from inside the editor — no need to switch to the board first.
- **"+ Create new task"** is right there in the same dropdown if nothing
  fits yet.

---

## 5. Opening a task: the ticket view

Click any card's title — on the board, in the Layout banner, or on a Visual
Editor bubble — and the full ticket opens as a modal:

![The ticket header: state, assignee, priority, workspace, description, and a list of every record the task covers, with a "reused elsewhere" warning on some of them](Images/10-ticket-covered-records.png)

This is where a card becomes a **file**, not just a label:

- **Covered records** — everything currently claimed by this task, each
  with **Preview** (open the pending version on the live site, without
  publishing) and **Discard** (throw away just that one change, keeping the
  rest of the task intact).
- **"reused elsewhere"** — a record referenced from more than one page.
  Discarding or publishing it affects every page that uses it, so this
  warning is there before you act, not after.
- Scroll down and you get the **full diff** of every text change, and the
  **history** of who moved this task where and when:

![A word-level diff of the text changes, with removed words struck through and added words underlined](Images/11-ticket-diff-view.png)

- At the very bottom, a **comment box** — for a note that isn't tied to a
  particular stage move, just something worth saying:

![The "Add a comment" box at the end of the ticket](Images/12-ticket-comment-box.png)

Everything you post here — comments, stage-change notes, discards — stays
attached to the task **permanently**, even after it's published and closed.
Nothing about a task's history disappears once the work is done.

---

## 6. Moving through the review stages

Dragging a card into a review-stage column (anything between *Editing* and
*Ready to publish*) is not a silent move — it opens TYPO3's own **"Send to
stage"** dialog, the same one you'd see in the Workspaces module:

![The "Send to stage" dialog: recipient checkboxes for each stage member, an additional-recipients box, and a comments field](Images/13-send-to-stage-dialog.png)

- Tick who should be notified (pre-filled with whoever is responsible for
  the *next* stage).
- Leave a comment explaining what's ready, or what needs a second look —
  it's attached to this exact transition in the task's history.

Confirm, and the card moves — with the comment count now visible on the
card itself:

![The card sitting in the Review column, with a comment badge](Images/14-board-card-review.png)

Depending on your workspace's setup, one person may be able to push a task
through more than one stage in a row (see [§7](#7-who-can-do-what) below):

![The same card now in Approval, with the assignee unchanged and the comment count at 1](Images/15-board-card-approval.png)

Here's the full pipeline at a glance once a few cards are moving through it
— Review, Approval, Ready to publish, Done, and the read‑only "Other
workspaces" column all in one row:

![The whole board, scrolled to show Review, Approval, Ready to publish, Done and Other workspaces columns side by side](Images/16-board-full-pipeline.png)

**"Other workspaces"** is a read-only parking spot: a task that belongs to a
workspace other than the one you're currently in shows up there — visible,
labelled, but nothing you can act on until you switch into it. It's there so
a task never silently vanishes just because your scope widened.

---

## 7. Who can do what

None of the permission logic above is something Content Flow invents — it
is entirely TYPO3's own workspace-stage model, applied the same way it
always has been. The rule in one sentence:

> **Whoever is responsible for the stage a task currently sits in may send
> it onward — including skipping several stages at once. Nobody needs
> permission for the stage they're moving a task *into*.**

Concretely, in a workspace with two custom stages (*Review*, *Approval*)
sitting between *Editing* and *Ready to publish*:

| Role | Can move… | Cannot move… |
|---|---|---|
| Any workspace member | *Editing* → anywhere (leaving Editing is always allowed) | — |
| Responsible for *Review* | *Review* → *Approval* (or further) | Anything sitting in *Approval* |
| Responsible for *Approval* | *Approval* → *Ready to publish* | Anything sitting in *Review* |
| Workspace owner | *Ready to publish* → **Publish**, and anything else, always | — |

Who counts as "responsible for a stage" is configured entirely in
**Settings → Workspaces → *(your workspace)* → Stages** — a person, a
group, or several. Content Flow adds no separate permission system on top
of it; if someone can already act on a stage in TYPO3's own Workspaces
module, they can do the exact same thing by dragging a card here.

**Publishing is different on purpose.** It is never a drag target — going
live is irreversible, so it is always an explicit button with a
confirmation, restricted to the workspace owner:

![The publish confirmation dialog: "This makes every pending change in this task live immediately. This cannot be undone."](Images/17-publish-confirmation.png)

Once confirmed, every pending change the task still held goes live in one
step, and the card lands in **Done** — closed, with its full history (every
comment, every stage change, every diff) preserved permanently:

![The card in the Done column, showing the original assignee and a comment count of 2](Images/18-board-task-done.png)

Back on the page itself, the loop closes exactly where it started:

![The Layout banner back to "No open task for this page", with a fresh "Plan task for this page" button](Images/19-layout-banner-closed.png)

---

## 8. Your dashboard

Four widgets are available for **Web → Dashboard** (add them via *Add a
widget* if they're not already on yours):

![Four Content Flow dashboard widgets: My tasks, Recent activity, Recent comments, and Task overview](Images/20-dashboard-widgets.png)

- **My tasks** — everything currently assigned to you, across every page.
- **Recent activity** — the newest entries from every task's history,
  board‑wide — a live feed of what's actually happening.
- **Recent comments** — catch up on discussion without opening each ticket.
- **Task overview** — how much work sits in each column, and (this one
  matters most) how much is still unassigned and up for grabs.

---

## What can I actually *do* with a task? — quick reference

| Action | Where | What it does |
|---|---|---|
| Create | Board, page banner | Opens the task wizard |
| Drag between Backlog / Planned | Board | Pure planning move, no dialog |
| Drag into a review stage | Board | Opens TYPO3's "Send to stage" dialog |
| Declare "this is my task" before editing | Visual Editor toolbar | Routes upcoming saves to it, before the fact |
| Assign to me | Board card, page banner | One click, for unassigned tasks |
| Comment | Ticket, VE comment popover | Attached permanently to the task's history |
| Preview a pending change | Ticket → *Preview* | Opens the live site with just that version overlaid |
| Discard one record | Ticket → *Discard* | Throws away that one change, keeps the rest of the task |
| See what changed | Ticket → *Diff* | Word-level before/after |
| Publish | Board card (owner only) | Irreversible, goes live immediately, closes the task |
| Manage a stage's review checklist | Board column ⚙️ (owner only) | Add/remove checklist items reviewers tick off |

---

## What's missing

Two different kinds of gaps turned up while putting this guide together —
worth knowing about, in different ways.

### If your own editors can't do anything at all: check permissions first

Every screenshot above was taken logged in as a real, non‑admin editor — and
getting there required fixing **five separate TYPO3 permission settings**
that a fresh installation does not grant automatically to a normal editor
group. If your editors report that the board "doesn't work" — buttons
missing, drags failing, dashboard empty — this is very likely why, not a bug
in Content Flow itself:

1. **Page edits refused** ("No page edit permission…") until the group's
   **Page Types** permission includes the relevant doktype.
2. **Content element edits refused** ("authMode explicitAllow failed for
   field CType…") until the group's **Explicit allow/deny** list includes
   every `CType` your editors need — TYPO3 denies *all* of them by default
   the moment this list is used at all, standard elements included.
3. **Dragging a card into a review stage fails** ("Could not open the
   TYPO3 workspace dialog") unless the group also has module access to
   **Workspaces** — even though editors never open that module directly.
   Content Flow's stage dialog reuses TYPO3's own workspace AJAX endpoint,
   which is gated on that access regardless.
4. **The Dashboard menu item is missing entirely** until the group has
   module access to **Dashboard**.
5. **"No widgets are available with your current permissions"** even with
   Dashboard access, until the group's **Available widgets** list includes
   Content Flow's four widgets specifically.

None of this is visible from an administrator account, since admins bypass
every one of these checks — which is exactly why it's easy to ship a demo
that looks perfect to the person building it and broken to everyone else.

### Product gaps — things not built yet

- **Card multi-select has no action attached to it yet.** Clicking a card
  selects it (you'll notice the highlight and hear it announced), and the
  server-side machinery for "select several records, hand them all to one
  task" already exists and works — but no button anywhere currently calls
  it. Right now, selecting cards doesn't do anything beyond selecting them.
- **No drag-to-reorder** for a stage's review checklist — items appear in
  the order they were added; reordering means removing and re-adding.
- **No bulk actions across tasks** (e.g. "assign all of these to me",
  "publish all of these") — everything is one task at a time.
- **The "Other workspaces" column is read-only** by design, but there's
  currently no shortcut from it straight into switching workspaces — you
  have to do that from TYPO3's own workspace selector.

If you run into anything else that feels missing, that's worth raising with
whoever maintains this extension for you — most of the above are the kind
of thing that's straightforward to add once someone asks for it.
