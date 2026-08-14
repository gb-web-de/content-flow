<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Record tables and page targets that TYPO3 will actually allow an editor to use.
 */
final readonly class RecordCreationTargetProvider
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private PageDoktypeRegistry $pageDoktypeRegistry,
    ) {
    }

    /**
     * @return list<array{table: string, label: string}>
     */
    public function getCreatableRecordTypes(BackendUserAuthentication $backendUser): array
    {
        $pages = $this->getAccessiblePages($backendUser);
        $types = [];
        foreach ($this->tcaSchemaFactory->all() as $table => $schema) {
            if (!$this->isCreatableRecordTable($table, $backendUser)) {
                continue;
            }
            if (!$this->hasEligiblePage($table, $pages, $backendUser)) {
                continue;
            }
            $types[] = [
                'table' => $table,
                'label' => $schema->getTitle($this->translate(...)),
            ];
        }

        usort($types, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
        return $types;
    }

    public function isCreatableRecordTable(string $table, BackendUserAuthentication $backendUser): bool
    {
        if ($table === 'pages' || !$this->tcaSchemaFactory->has($table)) {
            return false;
        }

        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->isWorkspaceAware()
            || !$schema->getCapability(TcaSchemaCapability::RestrictionRootLevel)->canExistOnPages()
            || $schema->hasCapability(TcaSchemaCapability::AccessReadOnly)
            || $schema->hasCapability(TcaSchemaCapability::HideInUi)
            || ($schema->hasCapability(TcaSchemaCapability::AccessAdminOnly) && !$backendUser->isAdmin())
        ) {
            return false;
        }

        return $backendUser->check('tables_modify', $table)
            && $backendUser->workspaceCanCreateNewRecord($table);
    }

    /**
     * @return list<array{uid: int, title: string, path: string}>
     */
    public function getEligiblePages(string $table, BackendUserAuthentication $backendUser): array
    {
        if (!$this->isCreatableRecordTable($table, $backendUser)) {
            return [];
        }

        $pages = array_values(array_filter(
            $this->getAccessiblePages($backendUser),
            fn (array $page): bool => $this->isPageEligibleRecord($table, $page, $backendUser),
        ));

        $targets = array_map(static fn (array $page): array => [
            'uid' => (int)$page['uid'],
            'title' => (string)$page['title'],
            'path' => (string)BackendUtility::getRecordPath((int)$page['uid'], '', 1000),
        ], $pages);
        usort($targets, static fn (array $left, array $right): int => strnatcasecmp($left['path'], $right['path']));
        return $targets;
    }

    public function isPageEligible(string $table, int $pageUid, BackendUserAuthentication $backendUser): bool
    {
        if ($pageUid < 1 || !$this->isCreatableRecordTable($table, $backendUser)) {
            return false;
        }

        foreach ($this->getAccessiblePages($backendUser) as $page) {
            if ((int)$page['uid'] === $pageUid) {
                return $this->isPageEligibleRecord($table, $page, $backendUser);
            }
        }
        return false;
    }

    public function getRecordTypeLabel(string $table): string
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return $table;
        }
        return $this->tcaSchemaFactory->get($table)->getTitle($this->translate(...)) ?: $table;
    }

    /**
     * @param list<array<string, mixed>> $pages
     */
    private function hasEligiblePage(string $table, array $pages, BackendUserAuthentication $backendUser): bool
    {
        foreach ($pages as $page) {
            if ($this->isPageEligibleRecord($table, $page, $backendUser)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function isPageEligibleRecord(string $table, array $page, BackendUserAuthentication $backendUser): bool
    {
        if (!$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return false;
        }
        if (!$this->pageDoktypeRegistry->isRecordTypeAllowedForDoktype($table, (int)($page['doktype'] ?? 0))) {
            return false;
        }

        $pageTs = BackendUtility::getPagesTSconfig((int)$page['uid'])['mod.']['web_list.'] ?? [];
        $allowedTables = GeneralUtility::trimExplode(',', (string)($pageTs['allowedNewTables'] ?? ''), true);
        $deniedTables = GeneralUtility::trimExplode(',', (string)($pageTs['deniedNewTables'] ?? ''), true);

        return !in_array($table, $deniedTables, true)
            && ($allowedTables === [] || in_array($table, $allowedTables, true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAccessiblePages(BackendUserAuthentication $backendUser): array
    {
        $repository = GeneralUtility::makeInstance(PageTreeRepository::class, (int)$backendUser->workspace);
        $repository->setAdditionalWhereClause($backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        $mounts = $backendUser->getWebmounts();
        $trees = [];
        if ($backendUser->isAdmin() || in_array(0, $mounts, true)) {
            $trees[] = $repository->getTree(0);
        } else {
            foreach ($mounts as $mount) {
                $tree = $repository->getTree($mount);
                if ($tree !== []) {
                    $trees[] = $tree;
                }
            }
        }

        $pages = [];
        foreach ($trees as $tree) {
            $this->flattenTree($tree, $pages);
        }
        return array_values($pages);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $pages
     */
    private function flattenTree(array $page, array &$pages): void
    {
        $uid = (int)($page['uid'] ?? 0);
        if ($uid > 0) {
            $pages[$uid] = $page;
        }
        foreach (($page['_children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->flattenTree($child, $pages);
            }
        }
    }

    private function translate(string $label): string
    {
        return ($GLOBALS['LANG'] ?? null)?->sL($label) ?: $label;
    }
}
