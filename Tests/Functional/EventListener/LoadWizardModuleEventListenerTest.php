<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\EventListener;

use GbWeb\ContentFlow\EventListener\LoadWizardModuleEventListener;
use GbWeb\ContentFlow\Service\AssignableUserProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The wizard modal only ever appears if wizard.js is loaded on whatever backend
 * page the editor happens to save from - the Page module, the List module, or a
 * direct record_edit link. Configuration/JavaScriptModules.php cannot express
 * "always load this"; only an AfterBackendPageRenderEvent listener can, which is
 * what this proves actually queues the module.
 */
final class LoadWizardModuleEventListenerTest extends FunctionalTestCase
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
        'gb-web/content-flow',
    ];

    #[Test]
    public function itQueuesTheWizardModuleOnPageRenderer(): void
    {
        $pageRenderer = $this->get(PageRenderer::class);
        (new LoadWizardModuleEventListener($pageRenderer, $this->get(AssignableUserProvider::class)))();

        // toArray() wraps each queued item as ['type' => ..., 'payload' => ...];
        // module instructions carry the actual JavaScriptModuleInstruction object
        // under 'payload'.
        $names = array_map(
            static fn (array $item): ?string => $item['payload'] instanceof JavaScriptModuleInstruction
                ? $item['payload']->getName()
                : null,
            $pageRenderer->getJavaScriptRenderer()->toArray(),
        );

        self::assertContains('@gb-web/content-flow/wizard.js', $names);
    }
}
