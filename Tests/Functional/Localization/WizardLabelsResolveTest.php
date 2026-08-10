<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Localization;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A label key that does not exist resolves to an empty string rather than
 * failing, so a typo in one reaches the editor as a blank error message and no
 * other test notices. These assert the keys the wizard actually asks for.
 */
final class WizardLabelsResolveTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['content_flow'];

    protected array $coreExtensionsToLoad = ['workspaces', 'dashboard'];

    #[Test]
    public function everyLabelTheWizardAsksForHasText(): void
    {
        $languageService = $this->get(LanguageServiceFactory::class)->create('default');

        $missing = [];
        foreach ($this->wizardLabelKeys() as $key) {
            if (trim($languageService->sL('content_flow.messages:' . $key)) === '') {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing, 'These label keys resolve to nothing: ' . implode(', ', $missing));
    }

    #[Test]
    public function everyErrorShownToAnEditorCarriesItsOwnCode(): void
    {
        $languageService = $this->get(LanguageServiceFactory::class)->create('default');

        $codes = [];
        foreach ($this->wizardLabelKeys() as $key) {
            if (!str_starts_with($key, 'wizard.error.')) {
                continue;
            }

            $label = $languageService->sL('content_flow.messages:' . $key);
            self::assertMatchesRegularExpression(
                '/\(CF-\d{4}\)$/',
                $label,
                sprintf('"%s" is shown to an editor but carries no error code.', $key),
            );

            preg_match('/\(CF-(\d{4})\)$/', $label, $matches);
            $codes[] = $matches[1];
        }

        self::assertSame(array_unique($codes), $codes, 'Two different errors share one code.');
    }

    /**
     * @return list<string>
     */
    private function wizardLabelKeys(): array
    {
        $source = file_get_contents(__DIR__ . '/../../../Classes/Wizard/TaskWizardProvider.php');
        self::assertIsString($source);

        preg_match_all("/translate\('([^']+)'/", $source, $matches);

        $keys = array_values(array_unique($matches[1]));
        self::assertNotEmpty($keys, 'No translate() calls found - has the provider been renamed?');

        return $keys;
    }
}
