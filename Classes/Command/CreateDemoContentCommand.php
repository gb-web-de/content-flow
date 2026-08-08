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
 * what this creates - plus three backend users spanning the actual permission
 * spread an integrator will hit.
 *
 * Core's own gate here (verified directly against
 * TYPO3\CMS\Workspaces\Hook\DataHandlerHook::version_setStage(), which calls
 * BackendUserAuthentication::workspaceCheckStageForCurrent($currentStage) - the
 * stage a record is LEAVING, not the one it is entering) means: whoever is
 * responsible for a stage may act on whatever currently sits there and send it
 * anywhere from there, including skipping stages ahead. It is not "may move
 * things into my stage", it is "may decide what happens to things already in my
 * stage". So: editor (responsible for Review) can act on anything sitting in
 * Review and send it onward; reviewer (responsible for Approval) likewise for
 * Approval; neither can act on the other's stage. Every member may always move
 * a record out of the default Editing stage (stage 0) - that part is not
 * role-specific. Only the workspace owner (approver) can act on records sitting
 * at "Ready to publish"/"Publish" or actually publish at all
 * (WorkspacePublishGate). None of this is a Content Flow rule - these demo
 * users exist to make core's own model visible on the board, not to route
 * around it.
 *
 * Re-running is safe: existing data is kept unless the user explicitly says
 * otherwise. Recreating deletes a workspace and therefore any versions inside it,
 * so it is never done implicitly - `--force`, or an interactive confirmation.
 */
#[AsCommand(
    name: 'contentflow:democontent',
    description: 'Ensure demo content, a workspace with review stages, and demo backend users exist for the board.',
)]
final class CreateDemoContentCommand extends Command
{
    private const WORKSPACE_TITLE = 'Editorial';
    private const STAGE_TITLES = ['Review', 'Approval'];
    private const GROUP_TITLE = 'Content Flow Editors';
    private const DEMO_PASSWORD = 'Password.1';

    /**
     * username => realName. "approver" is a workspace OWNER (full, unrestricted
     * access including publish) - deliberately not "responsible for a stage"
     * like the other two, because ownership is the one thing that grants acting
     * on "Ready to publish"/"Publish" and publishing itself; a stage-level
     * responsibility never grants that on its own.
     */
    private const DEMO_USERS = [
        'editor' => 'Erin Editor',
        'reviewer' => 'Rae Reviewer',
        'approver' => 'Ana Approver',
    ];

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
                $this->ensureDemoUsers($io, $existingWorkspaceUid);
                return Command::SUCCESS;
            }
            $this->deleteWorkspace($existingWorkspaceUid);
            $io->note(sprintf('Deleted workspace uid %d.', $existingWorkspaceUid));
        }

        $stageUidsByTitle = [];
        $workspaceUid = $this->createWorkspaceWithStages($stageUidsByTitle);
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

        $this->ensureDemoUsers($io, $workspaceUid, $stageUidsByTitle);

        $io->writeln('Switch into the workspace, edit a demo page, and a task appears on the board.');

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
     *
     * @param array<string, int> $stageUidsByTitle out parameter, filled with each
     *        created stage's uid keyed by its title
     */
    private function createWorkspaceWithStages(array &$stageUidsByTitle): int
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
        $stagePlaceholders = [];
        foreach (self::STAGE_TITLES as $stageTitle) {
            $placeholder = StringUtility::getUniqueId('NEW');
            $stagePlaceholders[$stageTitle] = $placeholder;
            $stageData['sys_workspace_stage'][$placeholder] = [
                'pid' => 0,
                'parentid' => $workspaceUid,
                'parenttable' => 'sys_workspace',
                'title' => $stageTitle,
            ];
        }

        $stageHandler = GeneralUtility::makeInstance(DataHandler::class);
        $stageHandler->start($stageData, []);
        $stageHandler->process_datamap();

        $stageUids = [];
        foreach ($stagePlaceholders as $stageTitle => $placeholder) {
            $stageUid = (int)($stageHandler->substNEWwithIDs[$placeholder] ?? 0);
            if ($stageUid > 0) {
                $stageUidsByTitle[$stageTitle] = $stageUid;
                $stageUids[] = $stageUid;
            }
        }

        $this->syncCustomStagesCounter($workspaceUid, $stageUids);

        return $workspaceUid;
    }

    /**
     * Both call sites (a fresh workspace above, or an existing one reused by
     * ensureDemoUsers()) create/found their stages via a plain `parentid` write
     * rather than submitting them through the workspace's own `custom_stages`
     * IRRE field, so core never ran its usual "maintain the parent's child
     * counter" step - the column can stay 0 even with real stages attached.
     * That counter is not decorative: BackendUserAuthentication::
     * workspaceCheckStageForCurrent() reads it to decide whether ANY per-stage
     * responsible_persons check applies at all. Left at 0, a non-owner member
     * can never enter a custom stage no matter what responsible_persons says -
     * so this is corrected explicitly, the way a real IRRE submission on the
     * parent would have, every time this command runs.
     *
     * @param list<int> $stageUids
     */
    private function syncCustomStagesCounter(int $workspaceUid, array $stageUids): void
    {
        if ($stageUids === []) {
            return;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace' => [
                $workspaceUid => ['custom_stages' => implode(',', $stageUids)],
            ],
        ], []);
        $dataHandler->process_datamap();
    }

    /**
     * Create (or reuse) the three demo backend users and their group, and wire
     * them into the workspace with a deliberately uneven permission spread -
     * verified end to end against the real DataHandlerHook gate, not assumed:
     *
     *   editor    - workspace member, responsible for "Review" -> may act on a
     *               task while it sits in Review and send it onward (including
     *               straight to Approval or further - stage responsibility is
     *               about what leaves your stage, not what enters it). Cannot
     *               act on a task sitting in Approval. Any member, including
     *               editor, may always move a task out of the default Editing
     *               stage.
     *   reviewer  - workspace member, responsible for "Approval" -> may act on
     *               a task while it sits in Approval and send it onward.
     *               Cannot act on a task sitting in Review. Cannot publish
     *               (WorkspacePublishGate is owner-only, independent of stage
     *               responsibility).
     *   approver  - workspace OWNER -> the one role that can act on a task at
     *               "Ready to publish"/"Publish" and actually publish; no
     *               stage-level responsibility grants that on its own.
     *
     * @param array<string, int> $stageUidsByTitle
     */
    private function ensureDemoUsers(SymfonyStyle $io, int $workspaceUid, array $stageUidsByTitle = []): void
    {
        if ($stageUidsByTitle === []) {
            $stageUidsByTitle = $this->findStagesForWorkspace($workspaceUid);
        }
        $this->syncCustomStagesCounter($workspaceUid, array_values($stageUidsByTitle));

        $groupUid = $this->ensureDemoGroup();
        $this->grantPageAccessToGroup($groupUid);

        $userUids = [];
        foreach (self::DEMO_USERS as $username => $realName) {
            $userUids[$username] = $this->ensureUser($username, $realName, $groupUid, $workspaceUid);
        }

        $this->setWorkspaceMembersAndOwners($workspaceUid, $userUids);

        if (isset($stageUidsByTitle['Review'])) {
            $this->setStageResponsible($stageUidsByTitle['Review'], $userUids['editor']);
        }
        if (isset($stageUidsByTitle['Approval'])) {
            $this->setStageResponsible($stageUidsByTitle['Approval'], $userUids['reviewer']);
        }

        $io->section('Demo backend users');
        $io->writeln('  Password for all three: ' . self::DEMO_PASSWORD);
        foreach (self::DEMO_USERS as $username => $realName) {
            $io->writeln(sprintf('  - %s (%s), uid %d', $username, $realName, $userUids[$username]));
        }
        $io->writeln('  editor -> can act on a task while it sits in Review (send it onward, even skipping ahead).');
        $io->writeln('  reviewer -> can act on a task while it sits in Approval. Neither can act on the other\'s stage.');
        $io->writeln('  approver -> workspace owner, can act on "Ready to publish" and actually publish.');
    }

    /**
     * @return array<string, int> stage title => uid
     */
    private function findStagesForWorkspace(int $workspaceUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace_stage');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('sys_workspace_stage')
            ->where(
                $queryBuilder->expr()->eq('parentid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $byTitle = [];
        foreach ($rows as $row) {
            $byTitle[(string)$row['title']] = (int)$row['uid'];
        }
        return $byTitle;
    }

    private function ensureDemoGroup(): int
    {
        // Every page permission check (BackendUserAuthentication::calcPerms())
        // starts with isInWebMount() - without a mount pointing at the site,
        // perms_groupid/perms_group are never even consulted, no matter how
        // they are set. Mounted at the true page-tree root(s) - the site's own
        // top-level pages (pid = 0) - so the whole demo site is reachable.
        $values = [
            'title' => self::GROUP_TITLE,
            // The content_flow module itself, plus the two core modules an
            // editor needs to reach a page and edit its content at all.
            'groupMods' => 'web_contentflow,web_layout,file_list',
            'tables_select' => 'pages,tt_content,sys_file_reference,sys_category',
            'tables_modify' => 'pages,tt_content,sys_file_reference,sys_category',
            'db_mountpoints' => implode(',', $this->findRootPageUids()),
        ];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $queryBuilder->getRestrictions()->removeAll();
        $existing = $queryBuilder
            ->select('uid')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(self::GROUP_TITLE)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if ($existing) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start(['be_groups' => [(int)$existing => $values]], []);
            $dataHandler->process_datamap();
            return (int)$existing;
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['be_groups' => [$placeholder => ['pid' => 0] + $values]], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * @return list<int>
     */
    private function findRootPageUids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int)$row['uid'], $rows);
    }

    /**
     * Demo pages carry no `perms_groupid` (see `pages.perms_everybody = 0` in
     * the Camino fixture), so without this no be_group has any page access at
     * all - not a workspace concern, but the demo users would otherwise be
     * unable to reach a single page. Applied to every existing page, not just
     * the root: TYPO3 does not cascade page permissions to subpages on its own.
     */
    private function grantPageAccessToGroup(int $groupUid): void
    {
        if ($groupUid < 1) {
            return;
        }
        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->update(
            'pages',
            [
                'perms_groupid' => $groupUid,
                // show, edit, delete, new-subpage, new-content - the same value
                // the demo fixture already grants perms_user for uid 1.
                'perms_group' => 31,
            ],
            ['deleted' => 0],
        );
    }

    private function ensureUser(string $username, string $realName, int $groupUid, int $workspaceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $existing = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        $values = [
            'realName' => $realName,
            'email' => $username . '@example.org',
            'usergroup' => (string)$groupUid,
            'workspace_id' => $workspaceUid,
            'admin' => 0,
            'disable' => 0,
        ];

        if ($existing) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start(['be_users' => [(int)$existing => $values]], []);
            $dataHandler->process_datamap();
            return (int)$existing;
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'be_users' => [
                $placeholder => $values + [
                    'pid' => 0,
                    'username' => $username,
                    'password' => self::DEMO_PASSWORD,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        return (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
    }

    /**
     * @param array<string, int> $userUids username => uid
     */
    private function setWorkspaceMembersAndOwners(int $workspaceUid, array $userUids): void
    {
        $members = [];
        $owners = [];
        foreach ($userUids as $username => $uid) {
            if ($uid < 1) {
                continue;
            }
            if ($username === 'approver') {
                $owners[] = 'be_users_' . $uid;
            } else {
                $members[] = 'be_users_' . $uid;
            }
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace' => [
                $workspaceUid => [
                    'members' => implode(',', $members),
                    'adminusers' => implode(',', $owners),
                ],
            ],
        ], []);
        $dataHandler->process_datamap();
    }

    private function setStageResponsible(int $stageUid, int $userUid): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_workspace_stage' => [
                $stageUid => ['responsible_persons' => 'be_users_' . $userUid],
            ],
        ], []);
        $dataHandler->process_datamap();
    }
}
