<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Decides what a task is about.
 *
 * Content Flow distinguishes two roles a versionable record can have:
 *
 * - a SUBJECT is page-like: it stands on its own and deserves its own card.
 *   `pages` obviously. But not only - a news record is technically a record while
 *   behaving like a page, because it has its own detail view. To an editor it *is*
 *   a page, so it gets its own task. Which tables count is configuration, not a
 *   hardcoded list, because only the integrator knows their content model.
 *
 * - everything else is page-bound: a content element belongs to the page it sits
 *   on, so editing it joins that page's task rather than opening a card of its own.
 *
 * Registration happens in ext_localconf.php and is open to other extensions:
 *
 *   $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['content_flow']['subjectTables'][]
 *       = 'tx_news_domain_model_news';
 */
class TaskSubjectRegistry
{
    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    /**
     * Page-like tables. `pages` is always one and cannot be removed - without it
     * there would be nothing for page-bound records to attach to.
     *
     * @return list<string>
     */
    public function getSubjectTables(): array
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['content_flow']['subjectTables'] ?? [];
        $tables = array_values(array_unique(array_merge(['pages'], array_filter((array)$configured, 'is_string'))));

        return array_values(array_filter($tables, fn(string $table): bool => $this->isTrackable($table)));
    }

    public function isSubject(string $table): bool
    {
        return in_array($table, $this->getSubjectTables(), true);
    }

    /**
     * Only versionable records can be tracked at all - "alle Objekte die
     * versionfähig sind". A record without workspace support never produces a
     * version, so there would be nothing for the workflow to move through stages.
     */
    public function isTrackable(string $table): bool
    {
        return $this->tcaSchemaFactory->has($table)
            && $this->tcaSchemaFactory->get($table)->isWorkspaceAware();
    }

    /**
     * Tables whose records get pulled into the task of the page they sit on.
     *
     * Everything trackable that is not itself a subject. Deliberately derived
     * rather than configured: a newly installed extension with a workspace-aware
     * table is aggregated automatically, instead of being silently ignored until
     * someone remembers to register it.
     *
     * @return list<string>
     */
    public function getAggregatableTables(): array
    {
        $subjects = $this->getSubjectTables();
        $tables = [];
        foreach ($this->tcaSchemaFactory->all()->getNames() as $table) {
            if (in_array($table, $subjects, true)) {
                continue;
            }
            if (!$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                continue;
            }
            $tables[] = $table;
        }
        return $tables;
    }

    /**
     * Resolve which task a record belongs to: itself when it is page-like,
     * otherwise the page it sits on.
     *
     * @return array{table: string, uid: int}|null null when the record cannot be
     *         tracked, or is page-bound but its page is unknown
     */
    public function resolveSubjectFor(string $table, int $uid): ?array
    {
        if (!$this->isTrackable($table)) {
            return null;
        }
        if ($this->isSubject($table)) {
            return ['table' => $table, 'uid' => $uid];
        }

        $record = BackendUtility::getRecord($table, $uid, 'pid');
        $pid = (int)($record['pid'] ?? 0);
        if ($pid < 1) {
            // Records on the root level have no page to belong to.
            return null;
        }
        return ['table' => 'pages', 'uid' => $pid];
    }
}
