<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds and, with --fix, heals the ways tx_contentflow_task_item can drift away
 * from tx_contentflow_task.
 *
 * The tables have no TCA and therefore no DataHandler management at all - a task
 * can currently only disappear via the race-loser cleanup in
 * TaskRepository::findOrCreateOpenForSubject() (which runs before any member
 * exists, so it cannot orphan one) or via manual intervention directly on the
 * database. Either way, nothing in the extension ever released the member rows
 * of a task that stopped existing, and the unique key
 * `one_open_task_per_record (record_table, record_uid, closed, deleted)` then
 * blocks that record from ever being claimed again - by any task, forever.
 *
 * Dry-run by default: this only ever reports what it found unless --fix is
 * given, the same one-step-back-from-destructive stance
 * CreateDemoContentCommand takes with --force.
 */
#[AsCommand(
    name: 'contentflow:repair',
    description: 'Report (and, with --fix, heal) task_item rows that outlived their task.',
)]
final class RepairTaskDataCommand extends Command
{
    private const TABLE_TASK = 'tx_contentflow_task';
    private const TABLE_ITEM = 'tx_contentflow_task_item';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'fix',
            null,
            InputOption::VALUE_NONE,
            'Actually write the repairs. Without this, only report what would change.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();
        $fix = (bool)$input->getOption('fix');

        $orphanCount = $this->repairOrphanedItems($io, $fix);
        $backfillCount = $this->backfillMissingPid($io, $fix);
        $this->reportEmptyTasks($io);

        if ($orphanCount === 0 && $backfillCount === 0) {
            $io->success('Nothing to repair.');
            return Command::SUCCESS;
        }

        if (!$fix) {
            $io->note('Dry run - nothing was written. Re-run with --fix to apply the repairs above.');
        }

        return Command::SUCCESS;
    }

    /**
     * A member row whose task no longer exists holds its record's slot in
     * one_open_task_per_record forever. Soft-deleting it releases that slot -
     * exactly what the task's own close() would have done had it closed
     * normally instead of vanishing.
     */
    private function repairOrphanedItems(SymfonyStyle $io, bool $fix): int
    {
        $taskQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $taskQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $existingTaskUids = array_map(
            'intval',
            $taskQueryBuilder
                ->select('uid')
                ->from(self::TABLE_TASK)
                ->where($taskQueryBuilder->expr()->eq('deleted', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        // DeletedRestriction is a no-op for both tables here (neither has TCA), so
        // `deleted` must be filtered explicitly - otherwise a row this same
        // command already fixed on a previous run keeps being reported, since
        // its task is still just as gone as it was before.
        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $orphans = $itemQueryBuilder
            ->select('uid', 'task', 'record_table', 'record_uid')
            ->from(self::TABLE_ITEM)
            ->where(
                $itemQueryBuilder->expr()->notIn(
                    'task',
                    $itemQueryBuilder->createNamedParameter($existingTaskUids === [] ? [0] : $existingTaskUids, Connection::PARAM_INT_ARRAY),
                ),
                $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($orphans === []) {
            $io->writeln('No orphaned task_item rows found.');
            return 0;
        }

        $io->section(sprintf('%d orphaned task_item row(s) (task no longer exists):', count($orphans)));
        $itemConnection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        foreach ($orphans as $orphan) {
            $io->writeln(sprintf(
                '  item %d: task %d (gone) held %s:%d',
                $orphan['uid'],
                $orphan['task'],
                $orphan['record_table'],
                $orphan['record_uid'],
            ));
            if ($fix) {
                $itemConnection->update(
                    self::TABLE_ITEM,
                    ['deleted' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
                    ['uid' => (int)$orphan['uid']],
                );
            }
        }

        return count($orphans);
    }

    /**
     * `pid` started mirroring `home_pid` only once TaskRepository::addMember()
     * began writing it - rows inserted before that keep the column's default of
     * 0. Backfilling from the sibling column that already holds the right value
     * needs no lookup against the actual record.
     *
     * Scoped to `deleted = 0`: a soft-deleted row is done being queried by
     * anything, so giving it a correct pid buys nothing and would otherwise
     * keep this command reporting "needs a repair" forever on historical rows.
     */
    private function backfillMissingPid(SymfonyStyle $io, bool $fix): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $stale = $queryBuilder
            ->select('uid', 'home_pid')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('home_pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($stale === []) {
            $io->writeln('No task_item rows need a pid backfill.');
            return 0;
        }

        $io->section(sprintf('%d task_item row(s) with pid=0 but a known home_pid:', count($stale)));
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        foreach ($stale as $row) {
            $io->writeln(sprintf('  item %d: pid 0 -> %d', $row['uid'], $row['home_pid']));
            if ($fix) {
                $connection->update(
                    self::TABLE_ITEM,
                    ['pid' => (int)$row['home_pid'], 'tstamp' => $GLOBALS['EXEC_TIME']],
                    ['uid' => (int)$row['uid']],
                );
            }
        }

        return count($stale);
    }

    /**
     * Reported only, never fixed: whether an empty task should be closed,
     * deleted, or left as a reminder that something needs attention is an
     * editorial call, not a data-integrity one.
     */
    private function reportEmptyTasks(SymfonyStyle $io): void
    {
        $taskQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_TASK);
        $taskQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $openTasks = $taskQueryBuilder
            ->select('uid', 'title')
            ->from(self::TABLE_TASK)
            ->where(
                $taskQueryBuilder->expr()->eq('closed', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $taskQueryBuilder->expr()->eq('deleted', $taskQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $itemQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $itemQueryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $tasksWithMembers = array_map(
            'intval',
            $itemQueryBuilder
                ->select('task')
                ->from(self::TABLE_ITEM)
                ->where(
                    $itemQueryBuilder->expr()->eq('closed', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $itemQueryBuilder->expr()->eq('deleted', $itemQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->groupBy('task')
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        $empty = array_filter($openTasks, static fn (array $task): bool => !in_array((int)$task['uid'], $tasksWithMembers, true));
        if ($empty === []) {
            $io->writeln('No open tasks without members.');
            return;
        }

        $io->section(sprintf('%d open task(s) with zero members (not fixed automatically):', count($empty)));
        foreach ($empty as $task) {
            $io->writeln(sprintf('  task %d: "%s"', $task['uid'], $task['title']));
        }
    }
}
