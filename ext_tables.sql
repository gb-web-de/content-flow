#
# Content Flow owns four tables. Base columns (uid, pid, tstamp, crdate, hidden)
# are added automatically by the TYPO3 schema analyzer from the TCA `ctrl` section
# and are intentionally not repeated here. `deleted` and `crdate` ARE declared
# where an index references them, because the analyzer only adds them once TCA
# exists and the indexes below must be creatable before that.
#

#
# One editorial work item.
#
# A task is about a SUBJECT - the page-like thing it represents. That is a page,
# but not only: any versionable record that behaves like a page gets its own task.
# The motivating case is a news record, which is technically a record but has its
# own detail page and reads as a page to an editor. Which tables count as subjects
# is configurable, see GbWeb\ContentFlow\Service\TaskSubjectRegistry.
#
# Everything else attaches to the subject's task as a member (see task_item):
# editing a content element on a page belongs to that page's task, it does not
# open a card of its own.
#
CREATE TABLE tx_contentflow_task (
    title varchar(255) DEFAULT '' NOT NULL,
    description text,

    # The page-like record this task represents.
    subject_table varchar(255) DEFAULT '' NOT NULL,
    subject_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # Denormalised so the board can scope by page tree without joining the target
    # table, and so the trail survives the subject record being deleted.
    subject_pid int(11) unsigned DEFAULT '0' NOT NULL,

    # Lifecycle. `state` is the GbWeb\ContentFlow\Domain\Model\TaskState value.
    # `stage_uid` is only meaningful while state->hasVersion() is true and then
    # mirrors the version's t3ver_stage - core stays the source of truth, this is
    # a read cache so the board can sort columns without touching every version.
    state varchar(20) DEFAULT 'backlog' NOT NULL,
    stage_uid int(11) DEFAULT '0' NOT NULL,

    # Workspace the versions live in. 0 while the task has no version yet.
    workspace_uid int(11) unsigned DEFAULT '0' NOT NULL,

    assignee int(11) unsigned DEFAULT '0' NOT NULL,
    due_date int(11) unsigned DEFAULT '0' NOT NULL,

    closed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    closed_at int(11) unsigned DEFAULT '0' NOT NULL,
    closed_by int(11) unsigned DEFAULT '0' NOT NULL,

    comments int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    KEY board_scope (subject_pid, closed, state),
    KEY subject (subject_table, subject_uid, closed),
    KEY assignee (assignee, closed)
);

#
# Which records belong to a task.
#
# A task on a page pulls in the content elements and records sitting on it, so one
# card covers "this page and everything on it" instead of flooding the board with a
# card per content element.
#
# An editor can pull an element out and give it its own task. That works without a
# "detached" flag, because of the unique key below: a record belongs to at most one
# OPEN task. Detaching moves the membership row to the new task, and re-syncing the
# page's task simply cannot reclaim the record, because the slot is taken.
#
# `closed` is denormalised from the task so the unique key only constrains open
# tasks - a record may of course appear in many closed ones over its lifetime.
#
CREATE TABLE tx_contentflow_task_item (
    task int(11) unsigned DEFAULT '0' NOT NULL,

    record_table varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,

    # subject = the record the task is named after
    # auto    = pulled in because it sits on the subject page
    # manual  = attached or detached by an editor
    origin varchar(10) DEFAULT 'auto' NOT NULL,

    closed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY one_open_task_per_record (record_table, record_uid, closed, deleted),
    KEY task (task, closed)
);

#
# Threaded comments on a task. A real table, not a JSON blob on the task:
# comments must be queryable (@mentions, dashboards, "unresolved" filters) and
# concurrently writable without read-modify-write races.
#
CREATE TABLE tx_contentflow_comment (
    task int(11) unsigned DEFAULT '0' NOT NULL,
    parent int(11) unsigned DEFAULT '0' NOT NULL,
    content text,
    resolved tinyint(1) unsigned DEFAULT '0' NOT NULL,

    KEY task (task, parent)
);

#
# Append-only editorial record: what was decided, by whom, when.
#
# This is the DURABLE store. sys_history is not: EXT:scheduler garbage-collects it
# after 30 days by default, so an archived task would otherwise lose its trail.
# Core does keep the trail across publishing (it migrates sys_history.recuid from
# the version uid to the live uid), so the problem is age, not publishing.
#
# Field-level before/after values are NOT copied. `history_uid` points at core's
# sys_history row holding that detail, for as long as it exists - a dangling
# pointer means "detail expired", not an error.
# See ARCHITECTURE.md, "Where the history lives".
#
CREATE TABLE tx_contentflow_activity (
    task int(11) unsigned DEFAULT '0' NOT NULL,
    event varchar(40) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    # sys_history row with the full detail, 0 if core wrote none.
    history_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # JSON. The essentials that must outlive sys_history: from/to stage, comment.
    payload json DEFAULT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    KEY task (task, crdate)
);
