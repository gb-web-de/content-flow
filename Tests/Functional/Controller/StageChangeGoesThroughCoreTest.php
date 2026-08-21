<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A stage change must be performed by TYPO3, not simulated by us.
 *
 * The board may propose a move; core decides it. That is not a style preference:
 * EXT:workspaces' version_setStage is what enforces workspaceCheckStageForCurrent()
 * and the page edit permission, writes t3ver_stage, records the transition in
 * sys_history and queues the stage notification mails. An implementation that
 * writes its own table instead silently skips all of it - which is exactly what
 * this extension did before.
 *
 * These tests assert on core's side effects rather than on ours, because ours
 * would look identical either way.
 */
final class StageChangeGoesThroughCoreTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->setWorkspace(1);
    }

    private function versionUidOf(string $table, int $liveUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(1)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stageHistoryFor(string $table, int $recordUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recordUid)),
                $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Drive the same command the controller builds, so the test covers the
     * contract with core rather than the controller's plumbing.
     */
    private function setStageViaCore(string $table, int $versionUid, int $stageId, string $comment): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [
            $table => [
                $versionUid => [
                    'version' => [
                        'action' => 'setStage',
                        'stageId' => $stageId,
                        'comment' => $comment,
                        'notificationAlternativeRecipients' => [],
                    ],
                ],
            ],
        ]);
        $dataHandler->process_cmdmap();

        return $dataHandler;
    }

    #[Test]
    public function coreWritesTheStageOntoTheVersion(): void
    {
        // Editing creates the version and the task.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => 'About us (revised)']]], []);
        $dataHandler->process_datamap();

        $versionUid = $this->versionUidOf('pages', 2);
        self::assertGreaterThan(0, $versionUid, 'a workspace version should exist');

        $result = $this->setStageViaCore('pages', $versionUid, 1, 'Ready for review.');
        self::assertSame([], $result->errorLog);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $stage = (int)$queryBuilder
            ->select('t3ver_stage')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($versionUid)))
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $stage, 'core must have written t3ver_stage');
    }

    #[Test]
    public function theTransitionAndItsCommentLandInSysHistory(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => 'About us (revised)']]], []);
        $dataHandler->process_datamap();

        $versionUid = $this->versionUidOf('pages', 2);
        $this->setStageViaCore('pages', $versionUid, 1, 'Images are still missing.');

        $entries = $this->stageHistoryFor('pages', $versionUid);
        self::assertCount(1, $entries, 'core should record exactly one stage change');

        $payload = json_decode((string)$entries[0]['history_data'], true);
        self::assertSame(1, (int)$payload['next']);
        // The comment is core's to keep - this is the entry that also feeds the
        // stage notification mail.
        self::assertSame('Images are still missing.', $payload['comment']);
    }

    #[Test]
    public function corePreventsAStageChangeOnARecordWithoutAVersion(): void
    {
        // uid 2 is the live record, not a version. Core must refuse rather than
        // quietly stamping a stage onto live content.
        $result = $this->setStageViaCore('pages', 2, 1, 'should not work');

        self::assertNotSame([], $result->errorLog, 'core must refuse this');
        self::assertSame([], $this->stageHistoryFor('pages', 2));
    }
}
