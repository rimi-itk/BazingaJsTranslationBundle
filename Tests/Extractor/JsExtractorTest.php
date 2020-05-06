<?php

namespace Bazinga\Bundle\JsTranslationBundle\Tests\Extractor;

use Bazinga\Bundle\JsTranslationBundle\Extractor\JsExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;

class JsExtractorTest extends TestCase
{
    private const TEST_LOCALE = 'en';
    private const TEST_KEY_1 = 'test-key-1';

    public function testExtractShouldNotRetrieveTransKey()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/NotValidTransFunctionUsage', $catalogue);
        $this->assertEmpty($catalogue->all());
    }

    public function testExtractShouldRetrieveTransKey()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/ATransFunctionUsage', $catalogue);
        $this->assertTrue($catalogue->has(self::TEST_KEY_1));
    }

    public function testExtractShouldNotRetrieveTransChoiceKey()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/NotValidTransChoiceFunctionUsage', $catalogue);
        $this->assertEmpty($catalogue->all());
    }

    public function testExtractShouldRetrieveTransChoiceKey()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/ATransChoiceFunctionUsage', $catalogue);
        $this->assertTrue($catalogue->has(self::TEST_KEY_1));
    }

    public function testExtractWithDomain()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/WithDomain', $catalogue);

        $this->assertCount(2, $catalogue->all('person'));
        $this->assertTrue($catalogue->has('name', 'person'));
        $this->assertTrue($catalogue->has('birthday', 'person'));
    }

    public function testExtractWithDomainRegex()
    {
        $catalogue = new MessageCatalogue(self::TEST_LOCALE);
        $extractor = new JsExtractor();
        $extractor->extract(__DIR__.'/../Fixtures/Extractor/WithDomainRegex', $catalogue);

        $this->assertCount(2, $catalogue->all('person'));
        $this->assertTrue($catalogue->has('name', 'person'));
        $this->assertTrue($catalogue->has('birthday', 'person'));
    }
}
