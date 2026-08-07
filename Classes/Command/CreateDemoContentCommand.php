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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Makes sure there is something to run the board against.
 *
 * Pages and content are NOT created here: theme_camino ships a full demo site in
 * Initialisation/data.xml (Camino, FAQs, Packing List, Route Comparison, plus
 * images), which TYPO3 imports during `typo3 setup` when TYPO3_SETUP_DISTRIBUTION
 * is set. This command only verifies that import happened.
 *
 * What Camino cannot provide is a workspace with custom review stages, and without
 * those the board has only the two fixed core stages - the whole
 * Backlog -> In Progress -> Review -> Ready -> Done flow stays invisible. That is
 * what this creates.
 *
 * Re-running is safe: existing data is kept unless the user explicitly says
 * otherwise. Recreating deletes a workspace and therefore any versions inside it,
 * so it is never done implicitly - `--force`, or an interactive confirmation.
 */
#[AsCommand(
    name: 'contentflow:democontent',
    description: 'Ensure demo content and a workspace with review stages exist for the board.',
)]
final class CreateDemoContentCommand extends Command
{
    private const WORKSPACE_TITLE = 'Editorial';
    private const STAGE_TITLES = ['Review', 'Approval'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Recreate the demo workspace even if it exists. Deletes it and any versions inside it.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Bootstrap::initializeBackendAuthentication();

        $this->reportPageContent($io);

        $existingWorkspaceUid = $this->findWorkspace();
        if ($existingWorkspaceUid > 0) {
            if (!$this->shouldRecreate($input, $io, $existingWorkspaceUid)) {
                $io->success(sprintf('Kept existing workspace "%s" (uid %d).', self::WORKSPACE_TITLE, $existingWorkspaceUid));
                return Command::SUCCESS;
            }
            $this->deleteWorkspace($existingWorkspaceUid);
            $io->note(sprintf('Deleted workspace uid %d.', $existingWorkspaceUid));
        }

        $workspaceUid = $this->createWorkspaceWithStages();
        if ($workspaceUid === 0) {
            $io->error('Could not create the demo workspace.');
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Created workspace "%s" (uid %d) with stages: %s.',
            self::WORKSPACE_TITLE,
            $workspaceUid,
            implode(', ', self::STAGE_TITLES),
        ));
        $io->writeln('Switch into it, edit a demo page, and a task appears on the board.');

        return Command::SUCCESS;
    }

    /**
     * Decide whether to replace existing demo data.
     *
     * When there is no TTY - which is the case for DDEV post-start hooks - Symfony
     * Console falls back to the default, so the answer is "keep". Destroying an
     * editor's workspace must never be the outcome of a non-interactive run.
     */
    private function shouldRecreate(InputInterface $input, SymfonyStyle $io, int $workspaceUid): bool
    {
        if ($input->getOption('force')) {
            return true;
        }
        if (!$input->isInteractive()) {
            $io->writeln(sprintf(
                'Workspace "%s" (uid %d) already exists - keeping it.',
                self::WORKSPACE_TITLE,
                $workspaceUid,
            ));
            $io->writeln('  To recreate it: <info>ddev contentflow-demo</info> (asks) or add <info>--force</info>.');
            return false;
        }

        $io->warning(sprintf(
            'Workspace "%s" (uid %d) already exists. Recreating deletes it and every version inside it.',
            self::WORKSPACE_TITLE,
            $workspaceUid,
        ));
        return $io->confirm('Recreate the demo workspace?', false);
    }

    /**
     * The pages come from the Camino distribution, not from here - so this only
     * checks and reports, it never creates pages.
     */
    private function reportPageContent(SymfonyStyle $io): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $pageCount = (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        if ($pageCount === 0) {
            $io->warning(
                'No pages found. The Camino demo site is imported by "typo3 setup" via '
                . 'TYPO3_SETUP_DISTRIBUTION=theme_camino and requires typo3/cms-impexp.',
            );
            return;
        }
        $io->writeln(sprintf('Found %d page(s) - demo content is present.', $pageCount));
    }

    private function findWorkspace(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();

        $uid = $queryBuilder
            ->select('uid')
            ->from('sys_workspace')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(self::WORKSPACE_TITLE)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    private function deleteWorkspace(int $workspaceUid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['sys_workspace' => [$workspaceUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();
    }

    /**
     * A workspace with two custom stages between "Editing" and "Ready to publish".
     *
     * Written through DataHandler rather than direct INSERTs so the records are
     * indistinguishable from hand-created ones - which matters here, because the
     * extension's own auto-creation hook is exactly what we want to exercise.
     */
    private function createWorkspaceWithStages(): int
    {
        $workspacePlaceholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace' => [
                $workspacePlaceholder => [
                    'pid' => 0,
                    'title' => self::WORKSPACE_TITLE,
                    'description' => 'Demo workspace created by content_flow',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $workspaceUid = (int)($dataHandler->substNEWwithIDs[$workspacePlaceholder] ?? 0);
        if ($workspaceUid === 0) {
            return 0;
        }

        $stageData = ['sys_workspace_stage' => []];
        foreach (self::STAGE_TITLES as $stageTitle) {
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
