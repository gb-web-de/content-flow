<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\View;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Renders the board template for real.
 *
 * This exists because of a bug the whole rest of the suite happily missed: the
 * template carried an HTML comment mentioning a ViewHelper tag in angle brackets.
 * Fluid parses ViewHelper syntax inside HTML comments too, so that opened a node
 * which was never closed, and the module died with a parse error on first open -
 * while every unit and functional test stayed green, because nothing rendered it.
 *
 * Any test that actually renders would have caught it. So: always render.
 */
final class TemplateRendersTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/content-flow',
    ];

    private function render(array $variables): string
    {
        $viewFactory = $this->get(ViewFactoryInterface::class);
        $view = $viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:content_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:content_flow/Resources/Private/Layouts/'],
        ));
        $view->assignMultiple($variables);

        return $view->render('ContentFlow/Index');
    }

    #[Test]
    public function theTemplateParsesAndRendersWithoutAPageSelected(): void
    {
        $output = $this->render(['pageSelected' => false, 'columns' => []]);

        self::assertStringContainsString('Select a page in the page tree', $output);
    }

    #[Test]
    public function theBoardRendersItsColumnsAndCards(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'backlog',
                    'label' => 'Backlog',
                    'state' => 'backlog',
                    'stageUid' => null,
                    'acceptsDrop' => true,
                    'cards' => [
                        [
                            'uid' => 1,
                            'title' => 'About us',
                            'subject_table' => 'pages',
                            'subject_uid' => 2,
                            'auto_created' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringContainsString('Backlog', $output);
        self::assertStringContainsString('About us', $output);
        self::assertStringContainsString('pages:2', $output);
        // The badge that marks a task nobody planned.
        self::assertStringContainsString('auto', $output);
        // The live region every board change is announced through.
        self::assertStringContainsString('aria-live="polite"', $output);
    }

    #[Test]
    public function aPlannedTaskShowsNoAutoBadge(): void
    {
        $output = $this->render([
            'pageSelected' => true,
            'columns' => [
                [
                    'key' => 'planned',
                    'label' => 'Planned',
                    'state' => 'planned',
                    'stageUid' => null,
                    'acceptsDrop' => true,
                    'cards' => [
                        [
                            'uid' => 2,
                            'title' => 'Products',
                            'subject_table' => 'pages',
                            'subject_uid' => 3,
                            'auto_created' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        self::assertStringNotContainsString('contentflow-card-badge', $output);
    }
}
