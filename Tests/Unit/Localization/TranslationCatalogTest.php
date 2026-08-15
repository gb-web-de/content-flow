<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Unit\Localization;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TranslationCatalogTest extends TestCase
{
    private const LANGUAGE_DIRECTORY = __DIR__ . '/../../../Resources/Private/Language/';

    #[Test]
    public function translatedCatalogsMatchEnglishSources(): void
    {
        foreach (['locallang.xlf', 'locallang_mod.xlf'] as $catalogName) {
            $sourceCatalog = $this->readCatalog(self::LANGUAGE_DIRECTORY . $catalogName);

            foreach (['de', 'fr'] as $language) {
                $translatedCatalog = $this->readCatalog(
                    self::LANGUAGE_DIRECTORY . $language . '.' . $catalogName,
                    $language,
                );

                self::assertSame(
                    array_keys($sourceCatalog),
                    array_keys($translatedCatalog),
                    sprintf('%s does not contain exactly the source catalog IDs.', $language . '.' . $catalogName),
                );

                foreach ($sourceCatalog as $id => $sourceUnit) {
                    $translatedUnit = $translatedCatalog[$id];
                    self::assertSame(
                        $sourceUnit['source'],
                        $translatedUnit['source'],
                        sprintf('%s:%s changed the English source text.', $language, $id),
                    );
                    self::assertNotSame(
                        '',
                        $translatedUnit['target'],
                        sprintf('%s:%s has no translation.', $language, $id),
                    );
                    self::assertSame(
                        $this->extractPlaceholders($sourceUnit['source']),
                        $this->extractPlaceholders($translatedUnit['target']),
                        sprintf('%s:%s does not preserve its placeholders.', $language, $id),
                    );
                    self::assertSame(
                        $this->extractErrorCodes($sourceUnit['source']),
                        $this->extractErrorCodes($translatedUnit['target']),
                        sprintf('%s:%s does not preserve its error code.', $language, $id),
                    );
                }
            }
        }
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private function readCatalog(string $path, ?string $expectedLanguage = null): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load($path, LIBXML_NONET), sprintf('%s is not valid XML.', $path));

        $file = $document->getElementsByTagName('file')->item(0);
        self::assertInstanceOf(DOMElement::class, $file);
        if ($expectedLanguage !== null) {
            self::assertSame($expectedLanguage, $file->getAttribute('target-language'));
        }

        $catalog = [];
        foreach ($document->getElementsByTagName('trans-unit') as $unit) {
            self::assertInstanceOf(DOMElement::class, $unit);
            $id = $unit->getAttribute('id');
            self::assertNotSame('', $id);
            self::assertArrayNotHasKey($id, $catalog, sprintf('%s contains duplicate ID %s.', $path, $id));

            $source = $unit->getElementsByTagName('source')->item(0);
            self::assertInstanceOf(DOMElement::class, $source);
            $target = $unit->getElementsByTagName('target')->item(0);

            $catalog[$id] = [
                'source' => trim($source->textContent),
                'target' => $target instanceof DOMElement ? trim($target->textContent) : '',
            ];
        }

        self::assertNotSame([], $catalog);

        return $catalog;
    }

    /**
     * @return list<string>
     */
    private function extractPlaceholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sd]/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function extractErrorCodes(string $text): array
    {
        preg_match_all('/CF-\d{4}/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}
