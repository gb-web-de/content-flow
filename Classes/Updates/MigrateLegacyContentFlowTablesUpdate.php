<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Copies rows from the six `tx_contentflow_*` tables the extension used before
 * its rename (extension key `content_flow`) into their `tx_editorialflow_*`
 * successors (extension key `editorial_flow`).
 *
 * Not a RENAME TABLE: "Analyze Database Structure" already created the new,
 * empty tables from ext_tables.sql by the time any upgrade wizard runs (see
 * DatabaseUpdatedPrerequisite below), so the new table name is already taken.
 * A copy also leaves the old data untouched as a safety net - nothing here
 * ever drops or truncates the `tx_contentflow_*` tables; removing them is a
 * separate, admin-timed step via the Install Tool's orphaned-table cleanup.
 *
 * Column lists are explicit (never `SELECT *`) so this does not depend on
 * column order matching between the old and new schema. UIDs are copied
 * verbatim - task/task_item/comment/activity/task_checklist_state
 * cross-reference each other by uid, and preserving them is what keeps those
 * references intact after the copy.
 */
#[UpgradeWizard('editorialFlowMigrateLegacyContentFlowTables')]
final class MigrateLegacyContentFlowTablesUpdate implements UpgradeWizardInterface, ChattyInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const TABLE_COLUMNS = [
        'tx_contentflow_task' => [
            'uid', 'pid', 'title', 'description', 'subject_table', 'subject_uid', 'subject_pid',
            'state', 'stage_uid', 'workspace_uid', 'assignee', 'start_date', 'due_date', 'sorting',
            'priority', 'auto_created', 'closed', 'closed_at', 'closed_by', 'comments',
            'tstamp', 'crdate', 'deleted',
        ],
        'tx_contentflow_task_item' => [
            'uid', 'pid', 'task', 'record_table', 'record_uid', 'origin', 'home_pid', 'shared',
            'closed', 'tstamp', 'crdate', 'deleted',
        ],
        'tx_contentflow_comment' => [
            'uid', 'pid', 'task', 'parent', 'activity', 'history_uid', 'be_user', 'content',
            'resolved', 'tstamp', 'crdate', 'deleted',
        ],
        'tx_contentflow_activity' => [
            'uid', 'pid', 'task', 'event', 'be_user', 'history_uid', 'payload',
            'tstamp', 'crdate', 'deleted',
        ],
        'tx_contentflow_stage_checklist_item' => [
            'uid', 'pid', 'workspace_uid', 'stage_uid', 'title', 'sorting',
            'tstamp', 'crdate', 'deleted',
        ],
        'tx_contentflow_task_checklist_state' => [
            'uid', 'pid', 'task', 'checklist_item', 'completed', 'completed_by', 'completed_at',
            'tstamp', 'crdate',
        ],
    ];

    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getTitle(): string
    {
        return 'Editorial Flow: migrate data from the content_flow tables';
    }

    public function getDescription(): string
    {
        return 'This extension was renamed from content_flow to editorial_flow. '
            . 'Copies every row still sitting in the old tx_contentflow_* tables into '
            . 'their tx_editorialflow_* successors, preserving uids so cross-references '
            . 'between tasks, comments, activity and checklists keep working. '
            . 'The old tables are left in place and can be removed manually afterwards.';
    }

    public function updateNecessary(): bool
    {
        foreach (self::TABLE_COLUMNS as $oldTable => $columns) {
            $newTable = $this->newTableName($oldTable);
            if ($this->rowCount($oldTable) > 0 && $this->rowCount($newTable) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function executeUpdate(): bool
    {
        foreach (self::TABLE_COLUMNS as $oldTable => $columns) {
            $newTable = $this->newTableName($oldTable);

            if (!$this->tableExists($oldTable)) {
                continue;
            }

            $oldCount = $this->rowCount($oldTable);
            if ($oldCount === 0) {
                continue;
            }

            if ($this->rowCount($newTable) > 0) {
                // Already migrated - nothing else can have written to a table
                // that was empty a moment ago under its brand-new name.
                $this->output?->writeln(sprintf('  %s: already migrated, skipping', $newTable));
                continue;
            }

            $connection = $this->connectionPool->getConnectionForTable($newTable);
            $quotedColumns = implode(', ', array_map(
                static fn (string $column): string => $connection->quoteIdentifier($column),
                $columns,
            ));

            $connection->executeStatement(sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s',
                $connection->quoteIdentifier($newTable),
                $quotedColumns,
                $quotedColumns,
                $connection->quoteIdentifier($oldTable),
            ));

            $this->output?->writeln(sprintf('  %s -> %s: %d row(s) copied', $oldTable, $newTable, $oldCount));
        }

        return true;
    }

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    private function newTableName(string $oldTable): string
    {
        return str_replace('tx_contentflow_', 'tx_editorialflow_', $oldTable);
    }

    private function tableExists(string $table): bool
    {
        $connection = $this->connectionPool->getConnectionForTable($table);

        return $connection->createSchemaManager()->tablesExist([$table]);
    }

    private function rowCount(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->count('uid')->from($table)->executeQuery()->fetchOne();
    }
}
