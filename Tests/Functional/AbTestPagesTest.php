<?php

declare(strict_types=1);

namespace Proudnerds\ABTestPages\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Proudnerds\ABTestPages\Domain\Repository\AbTestPageRepository;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class AbTestPagesTest extends FunctionalTestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/';

    protected array $coreExtensionsToLoad = [
        'typo3/cms-seo',
    ];

    protected array $testExtensionsToLoad = [
        'proudnerds/pn-abtestpages',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(self::FIXTURES . 'pages.csv');
    }

    public function testRepositoryFindsThePageTestingAVariant(): void
    {
        self::assertSame(2, $this->get(AbTestPageRepository::class)->findPageIdTestingVariant(3));
    }

    public function testRepositoryIgnoresHiddenTesters(): void
    {
        self::assertNull($this->get(AbTestPageRepository::class)->findPageIdTestingVariant(5));
    }

    public function testRepositoryIgnoresTranslatedRows(): void
    {
        self::assertNull($this->get(AbTestPageRepository::class)->findPageIdTestingVariant(6));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalProvider(): array
    {
        return [
            'B served under the URL of A' => ['/page-a', 'https://example.com/page-a'],
            'B opened on its own URL' => ['/page-b', 'https://example.com/page-a'],
            'page without a test' => ['/plain-page', 'https://example.com/plain-page'],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function testCanonicalPointsAtPageA(string $path, string $expectedCanonical): void
    {
        $this->setUpFrontendSite();

        $html = (string)$this->executeFrontendSubRequest(new InternalRequest('https://example.com' . $path))->getBody();

        self::assertStringContainsString('<link rel="canonical" href="' . $expectedCanonical . '">', $html);
    }

    public function testVariantServedUnderTheUrlOfAShowsTheContentOfB(): void
    {
        $this->setUpFrontendSite();

        $html = (string)$this->executeFrontendSubRequest(new InternalRequest('https://example.com/page-a'))->getBody();

        self::assertStringContainsString('Page B', $html);
    }

    public function testVariantIsServedOnAUrlWithACacheHashCalculatedForA(): void
    {
        $this->setUpFrontendSite();
        $cHash = $this->get(CacheHashCalculator::class)
            ->generateForParameters(HttpUtility::buildQueryString(['id' => 2, 'foo' => 'bar']));

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://example.com/page-a?foo=bar&cHash=' . $cHash));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Page B', (string)$response->getBody());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidVariantProvider(): array
    {
        return [
            'B is a folder' => ['/tester-of-folder', 'Tester of folder'],
            'B is in another site' => ['/tester-of-other-site', 'Tester of other site'],
        ];
    }

    #[DataProvider('invalidVariantProvider')]
    public function testInvalidVariantServesPageA(string $path, string $expectedTitle): void
    {
        $this->importCSVDataSet(self::FIXTURES . 'pages_invalid_variants.csv');
        $this->setUpFrontendSite();
        $this->get(SiteWriter::class)->write('other', [
            'rootPageId' => 30,
            'base' => 'https://other.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ],
        ]);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://example.com' . $path));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString($expectedTitle, (string)$response->getBody());
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
    }

    public function testVariantWithNoIndexGetsNoCanonical(): void
    {
        $this->setUpFrontendSite();

        $html = (string)$this->executeFrontendSubRequest(new InternalRequest('https://example.com/noindex-variant'))->getBody();

        self::assertStringNotContainsString('rel="canonical"', $html);
    }

    public function testTranslationFallsBackToAWhenBIsNotTranslated(): void
    {
        $this->importCSVDataSet(self::FIXTURES . 'pages_translated.csv');
        $this->setUpFrontendSite();

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://example.com/de/page-a'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Seite A', (string)$response->getBody());
    }

    public function testTranslationUsesTheTestSettingsOfTheDefaultLanguage(): void
    {
        // The translated row of A2 has no B page and 0 %, the default-language row has B2 at 100 %.
        // The middleware runs before the language aspect is set, so it always reads the default row.
        $this->importCSVDataSet(self::FIXTURES . 'pages_translated.csv');
        $this->setUpFrontendSite();

        $html = (string)$this->executeFrontendSubRequest(new InternalRequest('https://example.com/de/page-a2'))->getBody();

        self::assertStringContainsString('Seite B2', $html);
    }

    public function testNewPageDefaultsToACookieLifetimeOfOneDay(): void
    {
        $this->importCSVDataSet(self::FIXTURES . 'be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $request = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        $result = $this->get(FormDataCompiler::class)->compile(
            ['request' => $request, 'tableName' => 'pages', 'vanillaUid' => 1, 'command' => 'new'],
            $this->get(TcaDatabaseRecord::class),
        );

        self::assertSame(['86400'], $result['databaseRow']['tx_abtestpages_cookie_time']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function groupValueProvider(): array
    {
        return [
            'as submitted by FormEngine' => ['pages_3'],
            'as a plain uid' => ['3'],
        ];
    }

    #[DataProvider('groupValueProvider')]
    public function testChoosingABPageExcludesItFromSearch(string $groupValue): void
    {
        $this->importCSVDataSet(self::FIXTURES . 'be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [6 => ['tx_abtestpages_b_id' => $groupValue]]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $variantPage = $this->getConnectionPool()->getConnectionForTable('pages')
            ->select(['no_search'], 'pages', ['uid' => 3])
            ->fetchAssociative();
        self::assertSame(1, (int)($variantPage['no_search'] ?? 0));
    }

    private function setUpFrontendSite(): void
    {
        $this->setUpFrontendRootPage(1, ['EXT:pn_abtestpages/Tests/Functional/Fixtures/Basic.typoscript']);
        $this->get(SiteWriter::class)->write('test', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => '/',
                ],
                [
                    'languageId' => 1,
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/de/',
                    'fallbackType' => 'strict',
                ],
            ],
        ]);
    }
}
