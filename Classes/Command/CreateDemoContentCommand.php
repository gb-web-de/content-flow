<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Creates something to look at.
 *
 * The Camino theme does NOT ship demo content - on a fresh install it creates a site
 * and one empty page, nothing more. A board with no pages and no workspace shows
 * nothing at all, which makes the extension impossible to try out. This command fills
 * that gap: a handful of pages plus a workspace with two review stages, which is the
 * minimum needed to see Backlog -> In Progress -> Review -> Ready -> Done work.
 *
 * Everything goes through DataHandler rather than direct INSERTs, so the demo data is
 * indistinguishable from hand-created records (history, permissions, hooks all apply).
 */
#[AsCommand(
    name: 'contentflow:democontent',
    description: 'Create demo pages and a workspace with review stages to try the board with.',
)]
final class CreateDemoContentCommand extends Command
{
    private const DEMO_PAGES = [
        'Content Flow Demo',
        'About us',
        'Products',
        'Blog',
        'Contact',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        if ($this->demoAlreadyExists()) {
            $io->note('Demo content already present, nothing to do.');
            return Command::SUCCESS;
        }

        $rootPageUid = $this->findRootPage();
        if ($rootPageUid === 0) {
            $io->error('No page found to attach demo content to. Run "typo3 setup" first.');
            return Command::FAILURE;
        }

        $this->createPages($rootPageUid);
        $workspaceUid = $this->createWorkspaceWithStages();

        $io->success(sprintf(
            'Created %d demo pages under page %d and workspace %d with two review stages.',
            count(self::DEMO_PAGES),
            $rootPageUid,
            $workspaceUid,
        ));
        $io->writeln('Switch into the workspace, edit a page, and the board will show a task.');

        return Command::SUCCESS;
    }

    private function demoAlreadyExists(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (bool)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(self::DEMO_PAGES[0])),
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function findRootPage(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    private function createPages(int $rootPageUid): void
    {
        $data = ['pages' => []];
        // Negative pid means "after this record"; chaining the placeholders keeps the
        // demo pages in the order listed above instead of reversed.
        $previous = $rootPageUid;
        foreach (self::DEMO_PAGES as $title) {
            $placeholder = StringUtility::getUniqueId('NEW');
            $data['pages'][$placeholder] = [
                'pid' => $previous === $rootPageUid ? $rootPageUid : -$previous,
                'title' => $title,
                'doktype' => 1,
                'hidden' => 0,
            ];
            $previous = $placeholder;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
    }

    /**
     * A workspace with two custom stages between "Editing" and "Ready to publish",
     * so the board has review columns rather than just the two core defaults.
     */
    private function createWorkspaceWithStages(): int
    {
        $workspacePlaceholder = StringUtility::getUniqueId('NEW');
        $data = [
            'sys_workspace' => [
                $workspacePlaceholder => [
                    'pid' => 0,
                    'title' => 'Editorial',
                    'description' => 'Demo workspace created by content_flow',
                ],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();
        $workspaceUid = (int)($dataHandler->substNEWwithIDs[$workspacePlaceholder] ?? 0);

        if ($workspaceUid === 0) {
            return 0;
        }

        $stageData = ['sys_workspace_stage' => []];
        foreach (['Review', 'Approval'] as $stageTitle) {
            $stageData['sys_workspace_stage'][StringUtility::getUniqueId('NEW')] = [
                'pid' => 0,
                'parentid' => $workspaceUid,
                'parenttable' => 'sys_workspace',
                'title' => $stageTitle,
            ];
        }

        $stageHandler = GeneralUtility::makeInstance(DataHandler::class);
        $stageHandler->start($stageData, []);
        $stageHandler->process_datamap();

        return $workspaceUid;
    }
}
