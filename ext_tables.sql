#
# Content Flow owns three tables. Base columns (uid, pid, tstamp, crdate, deleted,
# hidden) are added automatically by the TYPO3 schema analyzer from the TCA `ctrl`
# section and are intentionally not repeated here.
#

#
# The task itself: one editorial work item, bound to exactly one record.
#
CREATE TABLE tx_contentflow_task (
    title varchar(255) DEFAULT '' NOT NULL,
    description text,

    # The record this task is about. Any table, not just pages/tt_content.
    record_table varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # Denormalised so the board can scope by page tree without joining the target
    # table, and so the trail survives the target record being deleted.
    record_pid int(11) unsigned DEFAULT '0' NOT NULL,

    # Lifecycle. `state` is the GbWeb\ContentFlow\Domain\Model\TaskState value.
    # `stage_uid` is only meaningful while state->hasVersion() is true and then
    # mirrors the version's t3ver_stage - core stays the source of truth, this is
    # a read cache so the board can sort columns without touching every version.
    state varchar(20) DEFAULT 'backlog' NOT NULL,
    stage_uid int(11) DEFAULT '0' NOT NULL,

    # Workspace the version lives in. 0 while the task has no version yet.
    workspace_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # uid of the t3ver record, 0 while unversioned. Cleared on publish.
    version_uid int(11) unsigned DEFAULT '0' NOT NULL,

    assignee int(11) unsigned DEFAULT '0' NOT NULL,
    due_date int(11) unsigned DEFAULT '0' NOT NULL,

    closed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    closed_at int(11) unsigned DEFAULT '0' NOT NULL,
    closed_by int(11) unsigned DEFAULT '0' NOT NULL,

    comments int(11) unsigned DEFAULT '0' NOT NULL,

    # One open task per record at a time. Enforced in the database rather than by a
    # read-then-write check, so two concurrent editors cannot both create a task for
    # the same record (see ARCHITECTURE.md, "Concurrency").
    # `closed` participates so a record may accumulate many closed tasks over time.
    UNIQUE KEY open_task_per_record (record_table, record_uid, closed, deleted),
    KEY board_scope (record_pid, closed, state),
    KEY assignee (assignee, closed)
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

    KEY task (task, crdate)
);
